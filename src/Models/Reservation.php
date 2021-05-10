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
        return $this->price - $this->payment;
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
            throw new ReservationException(410);
        }

        if ($this->confirmTotal($data) != $data['total']) {
            throw new ReservationException(411);
        }

        return true;

    }

    protected function confirmTotal($data)
    {
        $reservationCost = $data['price'];

        $extrasCost = 0;
        foreach($data['extras'] as $id => $properties) {
            $extrasCost += Extra::find($id)->calculatePrice($data, $properties['quantity']);
        }

        $locationCost = 0;

        if (config('resrv-config.enable_locations') == true) {            
            $locationCost += Location::find($data['location_start'])->extra_charge;
            $locationCost += Location::find($data['location_end'])->extra_charge;
        }

        return $reservationCost + $extrasCost + $locationCost;

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
