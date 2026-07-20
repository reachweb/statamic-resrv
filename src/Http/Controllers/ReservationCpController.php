<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\InvalidStateTransition;
use Reach\StatamicResrv\Exceptions\ManualReservationException;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Exceptions\UnknownPaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Jobs\ResendConfirmationEmail;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Resources\ReservationCalendarResource;
use Reach\StatamicResrv\Resources\ReservationResource;
use Reach\StatamicResrv\Support\ManualReservationCreator;
use Reach\StatamicResrv\Support\ReservationRefundProcessor;
use Statamic\Facades\Scope;
use Statamic\Facades\User as StatamicUser;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;

class ReservationCpController extends Controller
{
    use QueriesFilters;

    protected $reservation;

    protected $payment;

    public function __construct(Reservation $reservation, PaymentInterface $payment)
    {
        $this->reservation = $reservation;
        $this->payment = $payment;
    }

    public function indexCp()
    {
        return Inertia::render('resrv::Reservations/Index', [
            'filters' => Scope::filters('resrv', []),
            'listUrl' => cp_route('resrv.reservation.index'),
            'showUrlTemplate' => cp_route('resrv.reservation.show', 'RESRVURL'),
            'refundUrl' => cp_route('resrv.reservation.refund'),
            'resendUrl' => cp_route('resrv.reservation.resendConfirmation'),
            'calendarUrl' => cp_route('resrv.reservations.calendar'),
            'createUrl' => cp_route('resrv.reservations.create'),
            'confirmPaymentUrlTemplate' => cp_route('resrv.reservation.confirmPayment', 'RESRVURL'),
            'cancelAwaitingUrlTemplate' => cp_route('resrv.reservation.cancelAwaiting', 'RESRVURL'),
            'sendPaymentRequestUrlTemplate' => cp_route('resrv.reservation.sendPaymentRequest', 'RESRVURL'),
        ]);
    }

    public function calendarCp()
    {
        return Inertia::render('resrv::Reservations/Calendar', [
            'calendarJsonUrl' => cp_route('resrv.reservations.calendar.list'),
            'reservationsUrl' => cp_route('resrv.reservations.index'),
        ]);
    }

    public function calendar(Request $request)
    {
        $data = $request->validate([
            'start' => 'required|date',
            'end' => 'required|date',
        ]);

        // Parse dates using Carbon to handle various formats including ISO8601
        $start = Carbon::parse($data['start'])->startOfDay();
        $end = Carbon::parse($data['end'])->endOfDay();

        $reservations = $this->reservation->whereDate('date_start', '>=', $start)
            ->whereDate('date_end', '<=', $end)
            ->whereIn('status', ['confirmed', 'partner'])
            ->with(['rate', 'childs.rate'])
            ->orderBy('date_start')
            ->get();

        return response()->json(new ReservationCalendarResource($reservations));
    }

    public function index(FilteredRequest $request)
    {
        $query = $this->getReservations();

        $activeFilterBadges = $this->queryFilters($query, $request->filters);

        // Clamp so a huge ?perPage can't load/serialize unbounded rows (matches DynamicPricingCpController).
        $perPage = (int) (request('perPage') ?? config('statamic.cp.pagination_size', 25));
        $perPage = max(1, min($perPage, 100));

        $reservations = $query->paginate($perPage);

        return (new ReservationResource($reservations))
            ->columnPreferenceKey('resrv.reservations.columns')
            ->additional(['meta' => [
                'activeFilterBadges' => $activeFilterBadges,
            ]]);
    }

    public function show($id)
    {
        $reservation = $this->reservation
            ->with(['extras', 'options.values', 'affiliate', 'dynamicPricings', 'childs.rate'])
            ->findOrFail($id);

        $entry = $reservation->entry();
        $entryId = is_array($entry) ? null : $entry->id();

        return Inertia::render('resrv::Reservations/Show', [
            'reservation' => $this->serializeReservation($reservation, $entry),
            'fields' => $reservation->checkoutFormFieldsArray($entryId),
            'currencySymbol' => config('resrv-config.currency_symbol'),
            'maximumQuantity' => (int) config('resrv-config.maximum_quantity'),
            'backUrl' => cp_route('resrv.reservations.index'),
            'refundUrl' => cp_route('resrv.reservation.refund'),
            'confirmPaymentUrl' => cp_route('resrv.reservation.confirmPayment', ['id' => $reservation->id]),
            'cancelAwaitingUrl' => cp_route('resrv.reservation.cancelAwaiting', ['id' => $reservation->id]),
            'sendPaymentRequestUrl' => cp_route('resrv.reservation.sendPaymentRequest', ['id' => $reservation->id]),
        ]);
    }

    protected function serializeReservation(Reservation $reservation, $entry): array
    {
        $childRateLabels = $reservation->childs->map(fn ($child) => $child->getRateLabel());

        $rateLabel = $reservation->isParent()
            ? $childRateLabels->unique()->implode(', ')
            : ($reservation->rate?->title ?? 'Default');

        $entryArray = is_array($entry)
            ? $entry
            : $reservation->entryToArray($entry);

        return [
            'id' => $reservation->id,
            'reference' => $reservation->reference,
            'status' => $reservation->status,
            'type' => $reservation->type,
            'created_at' => $reservation->created_at?->format('d-m-Y H:i'),
            'date_start' => $reservation->date_start?->format('d-m-Y H:i'),
            'date_end' => $reservation->date_end?->format('d-m-Y H:i'),
            'quantity' => $reservation->quantity,
            'rate_id' => $reservation->rate_id,
            'rate_label' => $rateLabel,
            'entry' => $entryArray,
            'customer_data' => $reservation->customer_data->reject(
                fn ($value) => is_array($value) || $value === null
            )->all(),
            'childs' => $reservation->childs->map(fn ($child, $index) => [
                'id' => $child->id,
                'date_start' => $child->date_start?->format('d-m-Y H:i'),
                'date_end' => $child->date_end?->format('d-m-Y H:i'),
                'quantity' => $child->quantity,
                'rate_id' => $child->rate_id,
                'rate_label' => $childRateLabels[$index],
            ])->values()->all(),
            'options' => $reservation->options->map(function ($option) {
                $value = $option->values->firstWhere('id', $option->pivot->value);

                return [
                    'id' => $option->id,
                    'name' => $option->name,
                    'value_name' => $value?->name,
                    'price_type' => $value?->price_type,
                    'price_formatted' => $value?->price_type !== 'free' ? $value?->price?->format() : null,
                ];
            })->values()->all(),
            'extras' => $reservation->extras->map(fn ($extra) => [
                'id' => $extra->id,
                'name' => $extra->name,
                'quantity' => $extra->pivot->quantity,
                'price_formatted' => $extra->priceFromPivot(),
            ])->values()->all(),
            'affiliate' => $reservation->affiliate->isNotEmpty() ? (function () use ($reservation) {
                $affiliate = $reservation->affiliate->first();

                return [
                    'name' => $affiliate->name,
                    'email' => $affiliate->email,
                    'fee' => (float) $affiliate->pivot->fee,
                    'fee_amount_formatted' => $reservation->affiliateCommissionFor($affiliate)->format(),
                    'commission_cancelled' => $affiliate->pivot->cancelled_at !== null,
                ];
            })() : null,
            'dynamic_pricings' => $reservation->dynamicPricings->map(fn ($pricing) => [
                'id' => $pricing->id,
                'order' => $pricing->order,
                'title' => $pricing->title,
                'amount' => $pricing->amount,
                'amount_type' => $pricing->amount_type,
                'amount_operation' => $pricing->amount_operation,
            ])->values()->all(),
            'payment_gateway' => $reservation->payment_gateway,
            'payment_gateway_label' => $reservation->payment_gateway
                ? app(PaymentGatewayManager::class)->label($reservation->payment_gateway)
                : null,
            // Partner (skip-payment) rows keep the would-be deposit in the payment column
            // even though nothing was charged — null makes the page say so instead.
            'payment_formatted' => $reservation->status === ReservationStatus::PARTNER->value
                ? null
                : $reservation->payment->format(),
            'payment_surcharge_is_zero' => $reservation->payment_surcharge->isZero(),
            'payment_surcharge_formatted' => $reservation->payment_surcharge->format(),
            'total_to_charge_formatted' => $reservation->totalToCharge(),
            'price_formatted' => $reservation->price->format(),
            'total_formatted' => $reservation->total->format(),
            'affects_availability' => (bool) $reservation->affects_availability,
            'created_by' => $reservation->created_by,
            'created_by_name' => $reservation->created_by
                ? (StatamicUser::find($reservation->created_by)?->name() ?? $reservation->created_by)
                : null,
            'hold_expires_at' => $reservation->hold_expires_at?->format('d-m-Y H:i'),
            'payment_request_email_sent_at' => $reservation->payment_request_email_sent_at?->format('d-m-Y H:i'),
            'payment_url' => $reservation->customerPaymentUrl(),
        ];
    }

    public function refund(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
        ]);
        $reservation = $this->reservation->findOrFail($data['id']);

        try {
            $changed = app(ReservationRefundProcessor::class)->refund($reservation);
        } catch (RefundFailedException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        } catch (InvalidStateTransition $e) {
            return response()->json(['error' => 'Cannot refund a reservation in the '.$e->from->value.' state.'], 422);
        } catch (UnknownPaymentGateway $e) {
            return response()->json(['error' => 'The payment gateway ['.$e->gateway.'] recorded for this reservation is no longer configured. Refund the charge manually through that provider.'], 422);
        } catch (\Throwable $e) {
            // Unmapped failure (e.g. lock-wait QueryException under contention); transaction
            // rolled back so the charge was never touched. Return a retryable 503, not a raw 500.
            report($e);

            return response()->json(['error' => 'The refund could not be completed. Please try again.'], 503);
        }

        if (! $changed) {
            return response()->json(['error' => 'This reservation has already been refunded or cancelled.'], 409);
        }

        // The processor lands no-charge bookings in CANCELLED instead of REFUNDED; return the
        // terminal status so the UI can say "cancelled" instead of claiming money moved.
        return response()->json([
            'id' => $reservation->id,
            'status' => $reservation->status,
        ]);
    }

    public function resendConfirmation(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
        ]);
        $reservation = $this->reservation->findOrFail($data['id']);

        // The confirmation email only exists for reservations that actually reached a
        // confirmed state (a normal confirmed booking or an affiliate skip-payment one).
        // Pending/expired/refunded reservations never had a confirmation to resend.
        $resendable = [ReservationStatus::CONFIRMED->value, ReservationStatus::PARTNER->value];
        if (! in_array($reservation->status, $resendable, true)) {
            return response()->json(['error' => 'Only confirmed reservations can have their confirmation email resent.'], 422);
        }

        // Mirror the dispatcher's own recipient validation (trim + filter_var) so the response
        // reflects reality: a blank or malformed address would be dropped before sending, which
        // would otherwise leave the CP reporting success while nothing actually goes out.
        $customerEmail = trim((string) ($reservation->customer?->email ?? ''));
        if (blank($customerEmail) || ! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['error' => 'This reservation does not have a valid customer email address to send to.'], 422);
        }

        // The disabled (enabled=false) email switch only governs automatic lifecycle sending.
        // This is a deliberate, permission-gated manual action behind a confirmation modal, so it
        // intentionally overrides that switch and always resends — see ResendConfirmationEmail.
        ResendConfirmationEmail::dispatchAfterResponse($reservation);

        return response()->json($reservation->id);
    }

    /**
     * Mark an awaiting-payment (manual) reservation as paid and confirm it — the money
     * arrived outside a gateway flow (offline gateway, or in person for an online one).
     * Fires the same ReservationConfirmed chain as a webhook: confirmation emails + log.
     */
    public function confirmPayment(int $id)
    {
        $reservation = $this->reservation->findOrFail($id);

        if (! $reservation->isAwaitingPayment()) {
            return response()->json(['error' => 'Only awaiting-payment reservations can be confirmed manually.'], 422);
        }

        try {
            $changed = $reservation->transitionTo(ReservationStatus::CONFIRMED);
        } catch (InvalidStateTransition $e) {
            return response()->json(['error' => 'Cannot confirm a reservation in the '.$e->from->value.' state.'], 422);
        }

        if ($changed) {
            // The transition is already committed and this endpoint rejects retries (the row is no
            // longer awaiting payment), so the confirmation chain (activity log, emails) must fire
            // before the gateway round trips below — a provider hang or hard timeout inside the
            // reconciliation must not strand a CONFIRMED booking without its ReservationConfirmed
            // side effects. The email job defers until after the response, so it still renders the
            // post-settlement row. Mirrors the webhook path, which dispatches right after its
            // transition commits. For the same committed-and-unretryable reason a throwing
            // synchronous listener is contained here: it must neither 500 the request nor skip
            // the reconciliation below — that would leave a live intent chargeable on a
            // CONFIRMED booking.
            try {
                ReservationConfirmed::dispatch($reservation, ReservationConfirmed::VIA_CP);
            } catch (\Throwable $e) {
                report($e);
            }

            // The money arrived out of band. Reconcile the gateway on the transitioned row: void any
            // live intent the customer left on the online pay page and drop the payment_id so a later
            // refund/cancel treats this as a no-gateway-charge booking (mirroring an offline confirm)
            // instead of routing to the provider with an empty/voided intent that can only fail. An
            // intent that already captured real money (a webhook racing this confirm) is left intact
            // so that charge stays refundable. Tolerates gateway errors.
            app(ReservationRefundProcessor::class)->settlePaidOutOfBand($reservation);
        }

        return response()->json($this->serializeFreshReservation($reservation->id));
    }

    /**
     * Cancel an awaiting-payment (manual) reservation: the ReservationCancelled chain
     * restores stock only when the reservation decremented it (affects_availability),
     * voids any commission, logs, and emails the customer. Any pending intent is
     * cancelled after the transition commits — never inside the lock, and tolerantly:
     * an unreachable gateway leaves an intent that dies of old age.
     */
    public function cancelAwaitingPayment(int $id)
    {
        $reservation = $this->reservation->findOrFail($id);

        if (! $reservation->isAwaitingPayment()) {
            return response()->json(['error' => 'Only awaiting-payment reservations can be cancelled this way.'], 422);
        }

        try {
            // Tag the cancellation as an unpaid hold so the customer email says "nothing to refund"
            // rather than "non-refundable": an awaiting-payment booking never captured money, even
            // if the customer opened the pay link and left an unpaid (now-voided) intent id behind.
            // The in-transaction origin re-check is load-bearing (same as CancelLapsedHolds):
            // CANCELLED is also reachable from CONFIRMED, so without it a webhook or concurrent
            // CP confirm landing between the pre-check above and the row lock would let this
            // cancel a PAID booking with the unpaid-hold wording.
            app(ReservationRefundProcessor::class)->cancelWithoutRefund(
                $reservation,
                ReservationCancelled::CONTEXT_UNPAID_HOLD,
                inTransaction: function (Reservation $fresh) {
                    if (! $fresh->isAwaitingPayment()) {
                        throw new InvalidStateTransition(
                            ReservationStatus::from($fresh->status),
                            ReservationStatus::CANCELLED,
                            $fresh->id,
                        );
                    }
                },
                cancelOpenIntent: true,
            );
        } catch (InvalidStateTransition $e) {
            return response()->json(['error' => 'Cannot cancel a reservation in the '.$e->from->value.' state.'], 422);
        }

        return response()->json($this->serializeFreshReservation($reservation->id));
    }

    /**
     * (Re)send the payment request email for an awaiting-payment reservation. Unlike the
     * confirmation resend this respects the event's enabled switch — a site that turned
     * the customer_payment_request event off gets a 422 explaining that, not a silent
     * success. Sends inline so the sent-at stamp and the returned payload are accurate.
     */
    public function sendPaymentRequest(int $id)
    {
        $reservation = $this->reservation->findOrFail($id);

        if (! $reservation->isAwaitingPayment()) {
            return response()->json(['error' => 'Only awaiting-payment reservations can receive a payment request.'], 422);
        }

        $customerEmail = trim((string) ($reservation->customer?->email ?? ''));
        if (blank($customerEmail) || ! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['error' => 'This reservation does not have a valid customer email address to send to.'], 422);
        }

        try {
            if (! app(ManualReservationCreator::class)->sendPaymentRequestEmail($reservation)) {
                return response()->json(['error' => 'The payment request email is disabled in the email settings, so it was not sent.'], 422);
            }
        } catch (ManualReservationException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->serializeFreshReservation($reservation->id));
    }

    private function serializeFreshReservation(int $id): array
    {
        $reservation = $this->reservation
            ->with(['extras', 'options.values', 'affiliate', 'dynamicPricings', 'childs.rate'])
            ->findOrFail($id);

        return $this->serializeReservation($reservation, $reservation->entry());
    }

    private function getReservations()
    {
        // Only allow sorting by real, sortable columns (mirrors ReservationBlueprint's sortable
        // handles). An unlisted ?sort= would otherwise 500 on a relation/non-existent column, and
        // a non asc/desc ?order= throws from orderBy()'s direction validation.
        $sortableColumns = ['id', 'status', 'type', 'reference', 'date_start', 'date_end', 'payment', 'price', 'payment_gateway', 'created_at', 'updated_at'];

        $sortBy = in_array(request('sort'), $sortableColumns, true) ? request('sort') : 'created_at';
        $sortOrder = strtolower((string) request('order')) === 'asc' ? 'asc' : 'desc';

        $this->reservation = $this->reservation
            ->with(['customer', 'rate', 'extras', 'options', 'childs.rate'])
            ->orderBy($sortBy, $sortOrder);

        if (! request()->filled('search')) {
            return $this->reservation;
        }

        return $this->searchReservations();
    }

    private function searchReservations()
    {
        // Cap the term and neutralise LIKE wildcards so a user-supplied % or _ can't widen the
        // match (or force a full scan via a bare %). '\' is the default LIKE escape on MySQL and
        // Postgres, so escaped wildcards are matched literally; ordinary searches are unaffected.
        $search = addcslashes(mb_substr((string) request('search'), 0, 100), '%_\\');
        $searchTerm = "%{$search}%";

        return $this->reservation->where(function ($query) use ($searchTerm) {
            $query->where('id', 'like', $searchTerm)
                ->orWhere('reference', 'like', $searchTerm)
                ->orWhere('status', 'like', $searchTerm)
                ->orWhere('created_at', 'like', $searchTerm)
                ->orWhere('date_start', 'like', $searchTerm)
                ->orWhere('date_end', 'like', $searchTerm)
                ->orWhereHas('customer', function ($customerQuery) use ($searchTerm) {
                    $customerQuery->where('email', 'like', $searchTerm);
                });
        });
    }
}
