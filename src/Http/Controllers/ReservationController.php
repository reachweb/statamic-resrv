<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Events\ReservationCreated;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Http\Requests\ReservationCreateRequest;
use Reach\StatamicResrv\Http\Requests\ReservationUpdateRequest;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\View\View;

class ReservationController extends Controller
{
    protected $reservation;
    protected $payment;

    public function __construct(Reservation $reservation, PaymentInterface $payment)
    {
        $this->reservation = $reservation;
        $this->payment = $payment;
    }

    public function confirm(ReservationCreateRequest $request, $statamic_id)
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

        $this->assignOptions($reservation, $data);

        $this->assignExtras($reservation, $data);

        return response()->json($reservation->id);
    }

    public function start(ReservationCreateRequest $request)
    {
        $data = $request->validated();
        $statamic_id = $data['statamic_id'];

        // Set the quantity and advanced for backwards compatibility
        if (! Arr::exists($data, 'quantity')) {
            $data['quantity'] = 1;
        }
        if (! Arr::exists($data, 'advanced')) {
            $data['advanced'] = null;
        }

        $reloadedReservation = $this->handleReload($request);
        if ($reloadedReservation) {
            return $this->checkoutStartView($reloadedReservation);
        }

        try {
            $request->missing('dates')
            ? $this->reservation->confirmReservation($data, $statamic_id, false, false)
            : $this->reservation->confirmMultipleReservation($data, $statamic_id, false);
        } catch (ReservationException $exception) {
            return back()->with(['errors' => $exception->getMessage()]);
        }

        $request->missing('dates')
            ? $reservation = $this->createNormal($data, $statamic_id)
            : $reservation = $this->createMultiple($data, $statamic_id);

        ReservationCreated::dispatch($reservation);

        return $this->checkoutStartView($reservation);
    }

    public function update(ReservationUpdateRequest $request, Reservation $reservation)
    {
        $data = $request->validated();

        $dataWithDates = array_merge(
            $data,
            ...$reservation->get(['date_start', 'date_end', 'quantity', 'property', 'location_start', 'location_end'])->toArray(),
        );

        try {
            $this->reservation->confirmTotal($dataWithDates, $reservation->item_id);
        } catch (ReservationException $exception) {
            return response()->json(['error' => $exception->getMessage()], 412);
        }

        $reservation->update([
            'payment' => $data['payment'],
            'price' => $data['total'],
        ]);

        $this->assignOptions($reservation, $data);

        $this->assignExtras($reservation, $data);

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
            'price' => $data['total'] ?? '',
            'payment' => $data['payment'] ?? '',
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
            'price' => $data['total'] ?? '',
            'payment' => $data['payment'] ?? '',
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

    public function checkoutFormSubmit(Request $request, $reservation_id)
    {
        // Find the reservation
        $reservation = $this->reservation->find($reservation_id);

        // Check if the reservation request is expired
        if ($reservation->status == 'expired') {
            return response()->json(['error' => __('Your request has expired, please refresh and try again')], 412);
        }

        // Validate customer data
        $data = $request->validate($this->validationRules($reservation));

        // Create a payment intent
        $paymentIntent = $this->payment->paymentIntent($reservation->payment, $reservation, $data);

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

    public function checkoutCompleted(Request $request)
    {
        $confirm_id = $this->payment->confirm_id ?? 'id';

        $data = $request->validate([
            $confirm_id => 'required',
            $this->payment->confirmed_status ?? 'status' => 'sometimes|string',
            $this->payment->amount ?? 'amount' => 'sometimes',
        ]);

        // Find the reservation
        $reservation = $this->reservation->find($data[$confirm_id]);

        // Confim the reservation
        $reservation->status = 'confirmed';
        $reservation->payment_id = $data;
        $reservation->save();

        ReservationConfirmed::dispatch($reservation);

        if (config('resrv-config.checkout_completed_uri')) {
            $checkoutEntry = Entry::findByUri(config('resrv-config.checkout_completed_uri'), Site::current());
            $layout = Arr::get($checkoutEntry->toAugmentedArray(), 'collection')->value()->layout();
        }

        return (new View())
           ->template('statamic-resrv::checkout.checkout_completed')
           ->layout($layout ?? 'layout')
           ->with([
               'reservation' => $reservation,
               'entry' => $reservation->entry(),
           ])->cascadeContent($checkoutEntry ?? collect(['title' => 'Checkout completed']));
    }

    public function checkoutFailed(Request $request)
    {
        $confirm_id = $this->payment->confirm_id ?? 'id';

        $data = $request->validate([
            $confirm_id => 'required',
        ]);

        // Find the reservation
        $reservation = $this->reservation->find($data[$confirm_id]);

        // Confim the reservation
        $reservation->status = 'expired';
        $reservation->save();

        if (config('resrv-config.checkout_failed_uri')) {
            $checkoutEntry = Entry::findByUri(config('resrv-config.checkout_failed_uri'), Site::current());
            $layout = Arr::get($checkoutEntry->toAugmentedArray(), 'collection')->value()->layout();
        }

        return (new View())
           ->template('statamic-resrv::checkout.checkout_failed')
           ->layout($layout ?? 'layout')
           ->with([
               'reservation' => $reservation,
               'entry' => $reservation->entry(),
           ])->cascadeContent($checkoutEntry ?? collect(['title' => 'Checkout failed']));
    }

    protected function validationRules($reservation)
    {
        $rules = [];
        $form = $reservation->checkoutForm($reservation->item_id);
        foreach ($form as $field) {
            if (isset($field->config()['validate'])) {
                $rules[$field->handle()] = implode('|', $field->config()['validate']);
            } else {
                $rules[$field->handle()] = 'nullable';
            }
        }

        return $rules;
    }

    protected function assignExtras($reservation, $data)
    {
        if (array_key_exists('extras', $data) > 0) {
            $extrasToSync = collect($data['extras'])->mapWithKeys(function ($extra, $id) use ($reservation) {
                return [
                    $id => [
                        'quantity' => $extra['quantity'],
                        'price' => $this->getExtraPrice($id, $reservation),
                    ],
                ];
            });
            $this->reservation->find($reservation->id)->extras()->sync($extrasToSync);
        }
    }

    protected function assignOptions($reservation, $data)
    {
        if (array_key_exists('options', $data) > 0) {
            $optionsToSync = collect($data['options'])->mapWithKeys(function ($option, $id) {
                return [
                    $id => ['value' => $option['value']],
                ];
            });
            $this->reservation->find($reservation->id)->options()->sync($optionsToSync);
        }
    }

    protected function handleReload()
    {
        if (session()->has('resrv_reservation')) {
            $reservation = $this->reservation->find(session('resrv_reservation'));
            if ($reservation->status !== 'pending') {
                return false;
            }
            $expireAt = Carbon::parse($reservation->created_at)->add(config('resrv-config.minutes_to_hold'), 'minute');
            if ($expireAt < Carbon::now()) {
                return false;
            }

            return $reservation;
        }

        return false;
    }

    protected function checkoutStartView($reservation)
    {
        $data = [
            'date_start' => $reservation->date_start,
            'date_end' => $reservation->date_end,
            'quantity' => $reservation->quantity,
            'advanced' => $reservation->property,
            'item_id' => $reservation->item_id,
        ];

        $extras = Extra::getPriceForDates($data);
        $extras->transform(function ($extra) {
            $extra->conditions = (new Extra)->find($extra->id)->conditions()->get();

            return $extra;
        });

        $options = Option::entry($data['item_id'])
            ->where('published', true)
            ->with('values')
            ->get();
        foreach ($options as $index => $option) {
            $options[$index] = Option::find($option->id)->valuesPriceForDates($data);
        }

        if (config('resrv-config.checkout_uri')) {
            $checkoutEntry = Entry::findByUri(config('resrv-config.checkout_uri'), Site::current());
            $layout = Arr::get($checkoutEntry->toAugmentedArray(), 'collection')->value()->layout();
        }

        return (new View())
           ->template('statamic-resrv::checkout.checkout_start')
           ->layout($layout ?? 'layout')
           ->with([
               'reservation' => $reservation,
               'duration' => $reservation->duration(),
               'prices' => $reservation->getPrices(),
               'extras' => $extras->keyBy('slug'),
               'options' => $options->keyBy('slug'),
               'entry' => $reservation->entry(),
               'form' => $this->reservation->checkoutForm($reservation->item_id),
           ])->cascadeContent($checkoutEntry ?? collect(['title' => 'Checkout']));
    }
}
