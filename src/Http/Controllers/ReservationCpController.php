<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Exceptions\InvalidStateTransition;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
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
        $filters = Scope::filters('resrv', []);

        return view('statamic-resrv::cp.reservations.index', compact('filters'));
    }

    public function calendarCp()
    {
        return view('statamic-resrv::cp.reservations.calendar');
    }

    public function calendar(Request $request)
    {
        // TODO: better validation
        $data = $request->validate([
            'start' => 'required',
            'end' => 'required',
        ]);

        // Parse dates using Carbon to handle various formats including ISO8601
        $start = Carbon::parse($data['start'])->startOfDay();
        $end = Carbon::parse($data['end'])->endOfDay();

        $reservations = $this->reservation->whereDate('date_start', '>=', $start)
            ->whereDate('date_end', '<=', $end)
            ->whereIn('status', ['confirmed', 'partner'])
            ->orderBy('date_start')
            ->get();

        return response()->json(new ReservationCalendarResource($reservations));
    }

    public function index(FilteredRequest $request)
    {
        $query = $this->getReservations();

        $activeFilterBadges = $this->queryFilters($query, $request->filters);
        $perPage = request('perPage') ?? config('statamic.cp.pagination_size');

        $reservations = $query->paginate($perPage);

        return (new ReservationResource($reservations))
            ->columnPreferenceKey('resrv.reservations.columns')
            ->additional(['meta' => [
                'activeFilterBadges' => $activeFilterBadges,
            ]]);
    }

    public function show($id)
    {
        $reservation = $this->reservation->with('extras', 'options', 'affiliate', 'dynamicPricings')->find($id);
        $fields = $reservation->checkoutFormFieldsArray(is_array($reservation->entry()) ? null : $reservation->entry()->id());

        return view('statamic-resrv::cp.reservations.show', compact('reservation', 'fields'));
    }

    public function refund(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
        ]);
        $reservation = $this->reservation->find($data['id']);

        if ($reservation->status === ReservationStatus::REFUNDED->value) {
            return response()->json(['error' => 'This reservation has already been refunded.'], 409);
        }

        $currentStatus = ReservationStatus::from($reservation->status);
        if (! $currentStatus->canTransitionTo(ReservationStatus::REFUNDED)) {
            return response()->json(['error' => 'Cannot refund a reservation in the '.$reservation->status.' state.'], 422);
        }

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

    private function getReservations()
    {
        $sortOrder = request('order') ?? 'desc';
        $sortBy = request('sort') ?? 'created_at';

        $this->reservation = $this->reservation->orderBy($sortBy, $sortOrder);

        if (! request()->filled('search')) {
            return $this->reservation;
        }

        return $this->searchReservations();
    }

    private function searchReservations()
    {
        $search = request('search');
        $searchTerm = "%{$search}%";

        return $this->reservation->where(function ($query) use ($searchTerm) {
            $query->where('customer', 'like', $searchTerm)
                ->orWhere('id', 'like', $searchTerm)
                ->orWhere('reference', 'like', $searchTerm)
                ->orWhere('status', 'like', $searchTerm)
                ->orWhere('created_at', 'like', $searchTerm)
                ->orWhere('date_start', 'like', $searchTerm)
                ->orWhere('date_end', 'like', $searchTerm);
        });
    }
}
