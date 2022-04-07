<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Http\Requests\CheckoutFormRequest;
use Reach\StatamicResrv\Http\Requests\ReservationRequest;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Reservation;

class ReservationController extends Controller
{
    protected $reservation;
    protected $payment;

    public function __construct(Reservation $reservation, PaymentInterface $payment)
    {
        $this->reservation = $reservation;
        $this->payment = $payment;
    }

    public function confirm(ReservationRequest $request, $statamic_id)
    {
        $data = $request->validated();

        // Set the quantity and advanced for backwards compatibility
        if (! Arr::exists($data, 'quantity')) {
            $data['quantity'] = 1;
        }
        if (! Arr::exists($data, 'advanced')) {
            $data['advanced'] = null;
        }

        try {
            $request->missing('dates')
            ? $this->reservation->confirmReservation($data, $statamic_id)
            : $this->reservation->confirmMultipleReservation($data, $statamic_id);
        } catch (ReservationException $exception) {
            return response()->json(['error' => $exception->getMessage()], 412);
        }

        $request->missing('dates')
            ? $reservation = $this->createNormal($data, $statamic_id)
            : $reservation = $this->createMultiple($data, $statamic_id);

        ReservationCreated::dispatch($reservation);

        if (array_key_exists('options', $data) > 0) {
            foreach ($data['options'] as $id => $properties) {
                $this->reservation->find($reservation->id)->options()->attach($id, ['value' => $properties['value']]);
            }
        }

        if (array_key_exists('extras', $data) > 0) {
            foreach ($data['extras'] as $id => $properties) {
                $this->reservation->find($reservation->id)->extras()->attach($id, [
                    'quantity' => $properties['quantity'],
                    'price' => $this->getExtraPrice($id, $reservation),
                ]);
            }
        }

        return response()->json($reservation->id);
    }

    protected function createNormal($data, $statamic_id)
    {
        return $this->reservation->create([
            'status' => 'pending',
            'type' => 'normal',
            'reference' => $this->reservation->createRandomReference(),
            'item_id' => $statamic_id,
            'date_start' => $data['date_start'],
            'date_end' => $data['date_end'],
            'quantity' => $data['quantity'],
            'property' => $data['advanced'],
            'location_start' => $data['location_start'] ?? '',
            'location_end' => $data['location_end'] ?? '',
            'price' => $data['total'],
            'payment' => $data['payment'],
            'payment_id' => '',
            'customer' => '',
        ]);
    }

    protected function createMultiple($data, $statamic_id)
    {
        $dates = collect($data['dates']);
        $justDates = $dates->flatten()->filter(fn ($item) => strtotime($item));
        $parent = $this->reservation->create([
            'status' => 'pending',
            'type' => 'parent',
            'reference' => $this->reservation->createRandomReference(),
            'item_id' => $statamic_id,
            'date_start' => $justDates->min(),
            'date_end' => $justDates->max(),
            'quantity' => 1,
            'location_start' => $data['location_start'] ?? '',
            'location_end' => $data['location_end'] ?? '',
            'price' => $data['total'],
            'payment' => $data['payment'],
            'payment_id' => '',
            'customer' => '',
        ]);
        $dates->transform(function ($child) use ($parent) {
            return [
                'reservation_id' => $parent->id,
                'date_start' => $child['date_start'],
                'date_end' => $child['date_end'],
                'quantity' => $child['quantity'] ?? 1,
                'property' => $child['advanced'] ?? null,
            ];
        });
        $parent->childs()->createMany($dates);

        return $parent;
    }

    protected function getExtraPrice($id, $reservation)
    {
        if ($reservation->type !== 'parent') {
            return Extra::find($id)->priceForReservation($reservation);
        }
        $total = Price::create(0);
        $reservation->childs()->each(function ($child) use ($id, &$total) {
            $total = $total->add(Price::create(Extra::find($id)->priceForReservation($child)));
        });

        return $total->format();
    }

    public function checkoutForm($entry = null)
    {
        $form = $this->reservation->checkoutForm($entry);

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
