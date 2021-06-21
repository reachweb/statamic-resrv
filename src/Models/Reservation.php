<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Statamic\Facades\Form;
use Statamic\Facades\Entry;
use Reach\StatamicResrv\Database\Factories\ReservationFactory;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Location;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Carbon\Carbon;

class Reservation extends Model
{
    use HasFactory;

    protected $table = 'resrv_reservations';

    protected $guarded = [];

    protected $casts = [
        'customer' => AsCollection::class,
        'date_start' => 'datetime',
        'date_end' => 'datetime',
        'price' => PriceClass::class,
        'payment' => PriceClass::class,
    ];

    protected $appends = ['entry'];

    protected static function newFactory()
    {
        return ReservationFactory::new();
    }

    public function entry()
    {
        return Entry::find($this->item_id);
    }

    public function getPriceAttribute($value)
    {
        return Price::create($value);
    }
    
    public function getPaymentAttribute($value)
    {
        return Price::create($value);
    }

    public function getEntryAttribute()
    {
        return Entry::find($this->item_id)->toShallowAugmentedArray();
    }

    public function extras()
    {
        return $this->belongsToMany(Extra::class, 'resrv_reservation_extra')->withPivot('quantity');
    }

    public function location_start_data()
    {
        return $this->hasOne(Location::class, 'id', 'location_start');
    }
    
    public function location_end_data()
    {
        return $this->hasOne(Location::class, 'id', 'location_end');
    }

    public function amountRemaining()
    {
        return $this->price->subtract($this->payment)->format();
    }

    public function confirmReservation($data, $statamic_id)
    {
        $availability = new Availability;

        $checkAvailability = $availability->confirmAvailabilityAndPrice([
            'date_start' => $data['date_start'],
            'date_end' => $data['date_end'],
            'payment' => $data['payment'],
            'price' => $data['price'],
        ], $statamic_id);

        if ($checkAvailability == false) {
            throw new ReservationException(__('This item is not available anymore or the price has changed. Please refresh and try searching again!'));
        }

        $dbTotal = Price::create($this->confirmTotal($data));
        $frontendTotal = Price::create($data['total']);

        if (! $dbTotal->equals($frontendTotal)) {
            throw new ReservationException(__('The price for that reservation has changed. Please refresh and try again!'));
        }

        return true;

    }

    protected function confirmTotal($data)
    {
        $reservationCost = Price::create($data['price']);

        $extrasCost = Price::create(0);
        if (array_key_exists('extras', $data) > 0) {
            foreach($data['extras'] as $id => $properties) {
                $extrasCost->add(Extra::find($id)->calculatePrice($data, $properties['quantity']));
            }
        }    

        $locationCost = Price::create(0);

        if (config('resrv-config.enable_locations') == true) {            
            $locationCost->add(Location::find($data['location_start'])->extra_charge);
            $locationCost->add(Location::find($data['location_end'])->extra_charge);
        }

        return $reservationCost->add($extrasCost, $locationCost)->format();

    }

    public function createRandomReference()
    {
        return Str::upper(Str::random(6));
    }

    public function checkoutForm()
    {
        $form = $this->getForm();
        // If we have a country field add the names automatically
        foreach ($form as $index => $field) {
            if ($field->handle() == 'country') {
                $config = $field->config();
                $config['options'] = trans('statamic-resrv::countries');
                $field->setConfig($config);
            }
        }
        return $form;
    }
    
    public function checkoutFormFieldsArray()
    {
        $form = $this->getForm();
        $fields = [];
        foreach ($form as $item) {
            $fields[$item->handle()] = $item->config()['display'];
        }
        return $fields;
    }

    protected function getForm()
    {
        $formHandle = config('resrv-config.form_name', 'checkout');
        return Form::find($formHandle)->fields()->values();
    }

    public function expire()
    {
        $this->status = 'expired';
        $this->save();
        ReservationExpired::dispatch($this);
    }

}
