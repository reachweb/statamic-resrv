<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Http\Requests\CheckoutFormRequest;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Events\ReservationConfirmed;

class ReservationController extends Controller
{

    protected $reservation;
    protected $payment;

    public function __construct(Reservation $reservation, PaymentInterface $payment)
    {
        $this->reservation = $reservation;
        $this->payment = $payment;
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
            'options' => 'nullable|array',  
        ];

        if (config('resrv-config.enable_locations') == true) {
            $additional_rules = [
                'location_start' => 'required|integer',
                'location_end' => 'required|integer'
            ];
            $rules = array_merge($rules, $additional_rules);
        }

        $data = $request->validate($rules);

        try {
            $attemptReservation = $this->reservation->confirmReservation($data, $statamic_id);
        } catch (ReservationException $exception) {
            return response()->json(['error' => $exception->getMessage()], 412);
        }        

        $reservation = $this->reservation->create([
            'status' => 'pending',
            'reference' => $this->reservation->createRandomReference(),
            'item_id' => $statamic_id,
            'date_start' => $data['date_start'],
            'date_end' => $data['date_end'],
            'location_start' => (isset($data['location_start']) ? $data['location_start'] : ''),
            'location_end' => (isset($data['location_end']) ? $data['location_end'] : ''),
            'price' => $data['total'],
            'payment' => $data['payment'],
            'payment_id' => '',
            'customer' => '',
        ]);

        ReservationCreated::dispatch($reservation);
        
        if (array_key_exists('options', $data) > 0) {
            foreach ($data['options'] as $id => $properties) {
                $this->reservation->find($reservation->id)->options()->attach($id, ['value' => $properties['value']]);
            }
        }
        
        if (array_key_exists('extras', $data) > 0) {
            foreach ($data['extras'] as $id => $properties) {
                $this->reservation->find($reservation->id)->extras()->attach($id, ['quantity' => $properties['quantity']]);
            }
        }
        
        return response()->json($reservation->id);

    }

    public function checkoutForm()
    {
        $form = $this->reservation->checkoutForm();
        return response()->json($form);
    }

    public function checkoutFormSubmit(CheckoutFormRequest $request, $reservation_id)
    {   
        // Find the reservation
        $reservation = $this->reservation->find($reservation_id);

        // Check if the reservation request is expired
        if ($reservation->status == 'expired') {
            return response()->json(['error' => __('Your request has expired, please refresh and try again')], 412);
        }

        // Validate customer data
        $data = $request->validated();

        // Create a payment intent
        $paymentIntent = $this->payment->paymentIntent($reservation->payment, $reservation_id, $data);

        // Save customer data and payment id        
        $reservation->customer = $data;
        $reservation->payment_id = $paymentIntent->id;
        $reservation->save();     
        
        // Send back the client secret
        $client_secret = $paymentIntent->client_secret;

        return response()->json(compact('reservation', 'client_secret'));
    }

    public function checkoutConfirm($reservation_id)
    {   
        // Find the reservation
        $reservation = $this->reservation->find($reservation_id);

        // Confim the reservation        
        $reservation->status = 'confirmed';
        $reservation->save();

        ReservationConfirmed::dispatch($reservation);
        
        return response()->json($reservation_id);
    }
   
}
