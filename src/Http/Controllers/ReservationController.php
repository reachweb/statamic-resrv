<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Exceptions\ReservationException;

class ReservationController extends Controller
{

    protected $reservation;

    public function __construct(Reservation $reservation)
    {
        $this->reservation = $reservation;
    }

    public function confirm(Request $request, $statamic_id)
    {
        $rules = [
            'date_start' => 'required|date',
            'date_end' => 'required|date',
            'payment' => 'required|numeric',
            'price' => 'required|numeric',
            'total' => 'required|numeric',
            'extras' => 'nullable|array',  
        ];

        if (config('resrv-config.enable_locations') == true) {
            $additional_rules = [
                'location_start' => 'required|integer',
                'location_end' => 'required|integer'
            ];
            $rules = array_merge($rules, $additional_rules);
        }

        $data = $request->validate($rules);

        ray($data)->blue();

        try {
            $attemptReservation = $this->reservation->confirmReservation($data, $statamic_id);
        } catch (ReservationException $exception) {
            return response()->json(['error' => $exception->getMessage()]);
        }

        $reservation = $this->reservation->create([
            'status' => 'pending',
            'reference' => $this->reservation->createRandomReference(),
            'date_start' => $data['date_start'],
            'date_end' => $data['date_end'],
            'location_start' => (isset($data['location_start']) ? $data['location_start'] : ''),
            'location_end' => (isset($data['location_end']) ? $data['location_end'] : ''),
            'price' => $data['total'],
            'payment' => $data['payment'],
            'payment_id' => '',
            'customer' => '',
        ]);
        
        foreach ($data['extras'] as $id => $properties) {
            $this->reservation->find($reservation->id)->extras()->attach($id, ['quantity' => $properties['quantity']]);
        }
        
        return response()->json($reservation->id);


    }
}
