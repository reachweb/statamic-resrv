<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Exceptions\InvalidStateTransition;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Jobs\ResendConfirmationEmail;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Resources\ReservationCalendarResource;
use Reach\StatamicResrv\Resources\ReservationResource;
use Statamic\Facades\Scope;
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
                $fee = (float) $affiliate->pivot->fee;

                return [
                    'name' => $affiliate->name,
                    'email' => $affiliate->email,
                    'fee' => $fee,
                    'fee_amount_formatted' => $reservation->total->multiply($fee / 100)->format(),
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
            'payment_formatted' => $reservation->payment->format(),
            'payment_surcharge_is_zero' => $reservation->payment_surcharge->isZero(),
            'payment_surcharge_formatted' => $reservation->payment_surcharge->format(),
            'total_to_charge_formatted' => $reservation->totalToCharge(),
            'price_formatted' => $reservation->price->format(),
            'total_formatted' => $reservation->total->format(),
        ];
    }

    public function refund(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
        ]);
        $reservation = $this->reservation->findOrFail($data['id']);

        if ($reservation->status === ReservationStatus::REFUNDED->value) {
            return response()->json(['error' => 'This reservation has already been refunded.'], 409);
        }

        $currentStatus = ReservationStatus::from($reservation->status);
        if (! $currentStatus->canTransitionTo(ReservationStatus::REFUNDED)) {
            return response()->json(['error' => 'Cannot refund a reservation in the '.$reservation->status.' state.'], 422);
        }

        // Partner (affiliate skip-payment) and zero-payment reservations never create a payment
        // intent, so payment_id stays '' — there is no charge on the gateway to refund. Skip the
        // gateway call entirely or Stripe rejects the empty payment_intent and blocks the refund.
        // Anything else with an empty payment_id (e.g. a PENDING row whose cancelled intent may
        // still carry an orphaned charge) keeps going through the gateway so the failure surfaces
        // to the admin instead of silently marking captured money as refunded.
        $gatewayHoldsNoCharge = ($reservation->payment_id === '' || $reservation->payment_id === null)
            && ($currentStatus === ReservationStatus::PARTNER || $reservation->payment->isZero());

        if (! $gatewayHoldsNoCharge) {
            $manager = app(PaymentGatewayManager::class);
            try {
                $payment = $manager->forReservation($reservation);
            } catch (\InvalidArgumentException $e) {
                $payment = $manager->gateway();
            }
            try {
                $payment->refund($reservation);
            } catch (RefundFailedException $exception) {
                return response()->json(['error' => $exception->getMessage()], 400);
            }
        }

        try {
            $changed = $reservation->transitionTo(ReservationStatus::REFUNDED);
        } catch (InvalidStateTransition $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        if ($changed) {
            ReservationRefunded::dispatch($reservation);
        }

        return response()->json($reservation->id);
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

        if (blank($reservation->customer?->email)) {
            return response()->json(['error' => 'This reservation has no customer email address to send to.'], 422);
        }

        ResendConfirmationEmail::dispatchAfterResponse($reservation);

        return response()->json($reservation->id);
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
