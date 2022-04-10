<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Events\ReservationRefunded;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
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

        $reservations = $this->reservation->whereDate('date_start', '>=', $data['start'])
                        ->whereDate('date_end', '<=', $data['end'])
                        ->where('status', 'confirmed')
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
        $reservation = $this->reservation->with('location_start_data', 'location_end_data', 'extras', 'options')->find($id);
        $fields = $reservation->checkoutFormFieldsArray($reservation->entry()->id());

        return view('statamic-resrv::cp.reservations.show', compact('reservation', 'fields'));
    }

    public function refund(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
        ]);
        $reservation = $this->reservation->find($data['id']);
        try {
            $this->payment->refund($reservation);
        } catch (RefundFailedException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        }

        $reservation->status = 'refunded';
        $reservation->save();

        ReservationRefunded::dispatch($reservation);

        return response()->json($reservation->id);
    }

    private function getReservations()
    {
        $sortOrder = request('order') ?? 'desc';
        $sortBy = request('sort') ?? 'created_at';

        if (! request()->filled('search')) {
            return $this->reservation->orderBy($sortBy, $sortOrder);
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
