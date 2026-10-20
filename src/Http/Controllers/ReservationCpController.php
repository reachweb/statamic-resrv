<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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
            'extendHoldUrlTemplate' => cp_route('resrv.reservation.extendHold', 'RESRVURL'),
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

        // Awaiting-payment holds that took stock are shown too (styled apart by status), so the
        // calendar explains every reduction in availability; view-only holds took nothing.
        $reservations = $this->reservation->whereDate('date_start', '>=', $start)
            ->whereDate('date_end', '<=', $end)
            ->where(fn ($query) => $query->whereIn('status', ReservationStatus::live())
                ->orWhere(fn ($query) => $query->where('status', ReservationStatus::AWAITING_PAYMENT->value)
                    ->where('affects_availability', true)))
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
            'extendHoldUrl' => cp_route('resrv.reservation.extendHold', ['id' => $reservation->id]),
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

        $amountReceived = $reservation->amountReceived();
        $paymentDueAt = $reservation->isAwaitingPayment() ? $reservation->paymentDueAt() : null;

        return [
            'id' => $reservation->id,
            'reference' => $reservation->reference,
            'status' => $reservation->status,
            'status_label' => ReservationStatus::labelFor($reservation->status),
            'type' => $reservation->type,
            // Instants carry their zone (app timezone); stay dates are wall dates and do not.
            'created_at' => $reservation->created_at?->format('d-m-Y H:i T'),
            'date_start' => $reservation->date_start?->format('d-m-Y H:i'),
            'date_end' => $reservation->date_end?->format('d-m-Y H:i'),
            // ISO-8601 with offset for client-side formatting; stay dates are wall dates (their
            // leading Y-m-d) unless stays are charged by time.
            'created_at_iso' => $reservation->created_at?->toIso8601String(),
            'date_start_iso' => $reservation->date_start?->toIso8601String(),
            'date_end_iso' => $reservation->date_end?->toIso8601String(),
            'stay_dates_have_time' => ReservationResource::stayDatesHaveTime(),
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
                'date_start_iso' => $child->date_start?->toIso8601String(),
                'date_end_iso' => $child->date_end?->toIso8601String(),
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
            'hold_expires_at' => $reservation->hold_expires_at?->format('d-m-Y H:i T'),
            'hold_expires_at_iso' => $reservation->hold_expires_at?->toIso8601String(),
            'payment_request_email_sent_at' => $reservation->payment_request_email_sent_at?->format('d-m-Y H:i T'),
            'payment_request_email_sent_at_iso' => $reservation->payment_request_email_sent_at?->toIso8601String(),
            // The effective cut-off (hold deadline or end of the arrival day), while awaiting payment.
            'payment_due_at' => $paymentDueAt?->format('d-m-Y H:i T'),
            'payment_due_at_iso' => $paymentDueAt?->toIso8601String(),
            'payment_url' => $reservation->customerPaymentUrl(),
            // Null when receipt is unknown (a frontend bank-transfer booking confirms unpaid).
            'amount_paid_formatted' => $amountReceived?->format(),
            'payment_status_known' => $amountReceived !== null,
            // A refund of this row would only cancel it — no charge exists to return.
            'holds_no_charge' => $reservation->gatewayHoldsNoCharge(),
            // A partner booking's payment column is a would-be deposit nothing collected.
            'balance_formatted' => $reservation->status === ReservationStatus::PARTNER->value
                ? $reservation->total->format()
                : $reservation->total->subtract($reservation->payment)->format(),
            'payment_unresolved' => (bool) $reservation->payment_unresolved,
            'payment_reference' => $reservation->hasGatewayPayment() ? $reservation->payment_id : null,
            // Overdue flags only describe a booking that is still waiting for its payment.
            'hold_deadline_passed' => $reservation->isAwaitingPayment() && $reservation->holdDeadlinePassed(),
            'arrival_day_passed' => $reservation->isAwaitingPayment() && $reservation->arrivalDayPassed(),
            'payment_window_closed' => $reservation->isAwaitingPayment() && $reservation->paymentWindowClosed(),
            'refund_is_automatic' => $this->refundIsAutomaticFor($reservation),
            'customer_email' => $reservation->customer?->email,
            'customer_name' => $reservation->customerName(),
            'payment_gateway_supports_manual_confirmation' => $reservation->paymentGatewaySupportsManualConfirmation(),
            'can_extend_hold' => $reservation->isAwaitingPayment(),
        ];
    }

    /**
     * Whether a refund of this booking returns (or returned) the money through the gateway — what
     * the refund action reports — for rows a refund applies to; null where it means nothing.
     */
    protected function refundIsAutomaticFor(Reservation $reservation): ?bool
    {
        $applies = in_array($reservation->status, [
            ReservationStatus::CONFIRMED->value,
            ReservationStatus::PARTNER->value,
            ReservationStatus::REFUNDED->value,
        ], true);

        return $applies ? $reservation->refundIsAutomatic() : null;
    }

    public function refund(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
        ]);
        $reservation = $this->reservation->findOrFail($data['id']);

        // A refund only moves money when a charge reached a gateway that can refund via
        // API — an offline gateway's refund() is a bookkeeping no-op, so its payment_id
        // alone must not read as "money returned". Captured before the call so the flag
        // can't drift from what the processor actually saw.
        $refundIsAutomatic = $reservation->refundIsAutomatic();

        try {
            $changed = app(ReservationRefundProcessor::class)->refund($reservation);
        } catch (RefundFailedException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        } catch (InvalidStateTransition $e) {
            return response()->json([
                'error' => __('Cannot refund a reservation whose status is :status.', ['status' => $e->from->label()]),
                'status' => $e->from->value,
            ], 422);
        } catch (UnknownPaymentGateway $e) {
            return response()->json(['error' => __('The payment gateway [:gateway] recorded for this reservation is no longer configured. Refund the charge manually through that provider.', ['gateway' => $e->gateway])], 422);
        } catch (\Throwable $e) {
            // Unmapped failure (e.g. lock-wait QueryException under contention); transaction
            // rolled back so the charge was never touched. Return a retryable 503, not a raw 500.
            report($e);

            return response()->json(['error' => __('The refund could not be completed. Please try again.')], 503);
        }

        if (! $changed) {
            return response()->json(['error' => __('This reservation has already been refunded or cancelled.')], 409);
        }

        // The processor lands no-charge bookings in CANCELLED instead of REFUNDED; return the
        // terminal status so the UI can say "cancelled" instead of claiming money moved.
        // refund_is_automatic is false when no gateway returned the money (out-of-band or
        // offline payment) — the admin must be told to return those funds by hand.
        return response()->json([
            'id' => $reservation->id,
            'status' => $reservation->status,
            'refund_is_automatic' => $refundIsAutomatic,
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
            return response()->json(['error' => __('Only confirmed reservations can have their confirmation email resent.')], 422);
        }

        // Mirror the dispatcher's own recipient validation (trim + filter_var) so the response
        // reflects reality: a blank or malformed address would be dropped before sending, which
        // would otherwise leave the CP reporting success while nothing actually goes out.
        if (! $this->hasValidCustomerEmail($reservation)) {
            return response()->json(['error' => __('This reservation does not have a valid customer email address to send to.')], 422);
        }

        // The disabled (enabled=false) email switch only governs automatic lifecycle sending.
        // This is a deliberate, permission-gated manual action behind a confirmation modal, so it
        // intentionally overrides that switch and always resends — see ResendConfirmationEmail.
        ResendConfirmationEmail::dispatchAfterResponse($reservation);

        return response()->json($reservation->id);
    }

    /**
     * Mark an awaiting-payment (manual) reservation as paid out of band and confirm it,
     * firing the same ReservationConfirmed chain as a webhook.
     */
    public function confirmPayment(int $id)
    {
        $reservation = $this->reservation->findOrFail($id);

        if (! $reservation->isAwaitingPayment()) {
            return $this->staleStateResponse(__('This reservation can no longer be marked as paid — its status is now :status.'), $reservation->status);
        }

        try {
            $changed = $reservation->transitionTo(
                ReservationStatus::CONFIRMED,
                inTransaction: fn (Reservation $fresh) => app(ReservationRefundProcessor::class)->markConfirmedOutOfBand($fresh),
            );
        } catch (InvalidStateTransition $e) {
            return $this->staleStateResponse(__('This reservation can no longer be marked as paid — its status is now :status.'), $e->from->value);
        }

        if ($changed) {
            // The transition is committed and unretryable, so dispatch before the gateway round
            // trips below (a provider hang must not strand a CONFIRMED booking without its side
            // effects), and contain throwing listeners — they must neither 500 nor skip the
            // reconciliation below.
            try {
                ReservationConfirmed::dispatch($reservation, ReservationConfirmed::VIA_CP);
            } catch (\Throwable $e) {
                report($e);
            }

            // Reconcile the gateway: void any live intent and drop payment_id so later
            // refund/cancel treats this as a no-gateway-charge booking. An intent that already
            // captured money is left intact so the charge stays refundable. Tolerates gateway errors.
            app(ReservationRefundProcessor::class)->settlePaidOutOfBand($reservation);
        } else {
            // A no-op transition means a webhook confirm (or second admin) won the race to
            // CONFIRMED. Still reconcile — skipping would hide a duplicate payment (cash AND a
            // captured charge) — and re-fetch the row: on a failed transition the in-memory model
            // is a stale awaiting-payment snapshot. A prior reconcile left payment_id empty,
            // making this a no-op.
            app(ReservationRefundProcessor::class)->settlePaidOutOfBand($this->reservation->findOrFail($id));
        }

        return response()->json($this->serializeFreshReservation($reservation->id));
    }

    /**
     * Cancel an awaiting-payment (manual) reservation via the ReservationCancelled chain.
     * Any pending intent is cancelled after the transition commits — never inside the
     * lock, and tolerantly (an unreachable gateway leaves an intent that dies of old age).
     * `notify_customer` (default true) false skips only the customer email.
     */
    public function cancelAwaitingPayment(Request $request, int $id)
    {
        $data = $request->validate(['notify_customer' => 'sometimes|boolean']);

        $reservation = $this->reservation->findOrFail($id);

        if (! $reservation->isAwaitingPayment()) {
            return $this->staleStateResponse(__('This reservation can no longer be cancelled this way — its status is now :status.'), $reservation->status);
        }

        try {
            // Tag as an unpaid hold so the customer email says "nothing to refund". The
            // in-transaction origin re-check is load-bearing (same as CancelLapsedHolds):
            // CANCELLED is also reachable from CONFIRMED, so a confirm landing between the
            // pre-check and the row lock must not let this cancel a PAID booking.
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
                notifyCustomer: (bool) ($data['notify_customer'] ?? true),
            );
        } catch (InvalidStateTransition $e) {
            return $this->staleStateResponse(__('This reservation can no longer be cancelled this way — its status is now :status.'), $e->from->value);
        }

        return response()->json($this->serializeFreshReservation($reservation->id));
    }

    /**
     * (Re)send the payment request email. Unlike the confirmation resend this respects the
     * event's enabled switch (422, not silent success). Sends inline so the sent-at stamp
     * and returned payload are accurate.
     */
    public function sendPaymentRequest(int $id)
    {
        $reservation = $this->reservation->findOrFail($id);

        if (! $reservation->isAwaitingPayment()) {
            return $this->staleStateResponse(__('A payment request cannot be sent for this reservation — its status is now :status.'), $reservation->status);
        }

        if (($error = $this->sendPaymentRequestError($reservation)) !== null) {
            return $error;
        }

        return response()->json($this->serializeFreshReservation($reservation->id));
    }

    /**
     * Give an awaiting-payment hold a new deadline (now + hold_days days) or remove it (null) —
     * also after the old deadline passed but before the lapse sweep ran — optionally re-sending
     * the payment request. The deadline write is row-locked against a concurrent confirm/cancel.
     */
    public function extendHold(Request $request, int $id)
    {
        $data = $request->validate([
            'hold_days' => ['present', 'nullable', 'integer', 'min:1', 'max:365'],
            'resend' => ['sometimes', 'boolean'],
        ], [
            'hold_days.present' => __('Enter the number of days to hold the reservation.'),
            'hold_days.integer' => __('Enter the hold as a whole number of days.'),
            'hold_days.min' => __('Hold the reservation for at least 1 day.'),
            'hold_days.max' => __('Hold the reservation for at most :max days.'),
        ]);

        $this->reservation->findOrFail($id);
        $message = __('The hold on this reservation cannot be changed — its status is now :status.');

        $currentStatus = DB::transaction(function () use ($id, $data) {
            $fresh = Reservation::query()->lockForUpdate()->findOrFail($id);

            if (! $fresh->isAwaitingPayment()) {
                return $fresh->status;
            }

            $fresh->hold_expires_at = $data['hold_days'] !== null ? now()->addDays((int) $data['hold_days']) : null;
            $fresh->save();

            return null;
        });

        if ($currentStatus !== null) {
            return $this->staleStateResponse($message, $currentStatus);
        }

        $sendError = null;

        if ($request->boolean('resend')) {
            $sendError = $this->sendPaymentRequestError($this->reservation->findOrFail($id), deadlineChanged: true)?->getData(true)['error'] ?? null;
        }

        return response()->json(array_merge($this->serializeFreshReservation($id), [
            'payment_request_sent' => $request->boolean('resend') && $sendError === null,
            'payment_request_error' => $sendError,
        ]));
    }

    /** Send the payment request through the creator's guards; null on success, the 422 otherwise. */
    protected function sendPaymentRequestError(Reservation $reservation, bool $deadlineChanged = false): ?JsonResponse
    {
        if (! $this->hasValidCustomerEmail($reservation)) {
            return response()->json(['error' => __('This reservation does not have a valid customer email address to send to.')], 422);
        }

        try {
            if (! app(ManualReservationCreator::class)->sendPaymentRequestEmail($reservation, $deadlineChanged)) {
                return response()->json(['error' => __('The payment request email is disabled in the email settings, so it was not sent.')], 422);
            }
        } catch (ManualReservationException $e) {
            // The sender re-reads the row under its lock; a state change since the pre-check is stale state.
            $fresh = $this->reservation->find($reservation->id);

            if ($fresh && ! $fresh->isAwaitingPayment()) {
                return $this->staleStateResponse(__('A payment request cannot be sent for this reservation — its status is now :status.'), $fresh->status);
            }

            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            // A mail transport failure: nothing was stamped, and extend-hold has already saved its
            // deadline — report it as an unsent email, not a failed request.
            report($e);

            return response()->json(['error' => __('The payment request email could not be sent. Check the mail settings and try again.')], 422);
        }

        return null;
    }

    /**
     * The row left the state the action needs (a webhook, the lapse sweep or another admin got
     * there first): 422 naming the current status, which the CP uses to refresh its view.
     */
    protected function staleStateResponse(string $message, string $status): JsonResponse
    {
        return response()->json([
            'error' => str_replace(':status', ReservationStatus::labelFor($status), $message),
            'status' => $status,
        ], 422);
    }

    /** Mirrors the email dispatcher's recipient validation (trim + filter_var). */
    protected function hasValidCustomerEmail(Reservation $reservation): bool
    {
        $email = trim((string) ($reservation->customer?->email ?? ''));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
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
