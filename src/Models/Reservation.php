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
use Reach\StatamicResrv\Models\AdvancedAvailability;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Location;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;

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
        return Entry::find($this->item_id) ?? $this->emptyEntry();        
    }

    public function getPriceAttribute($value)
    {
        return Price::create($value);
    }
    
    public function getPaymentAttribute($value)
    {
        return Price::create($value);
    }

    public function getPropertyAttributeLabel()
    {
        if ($this->property == null) {
            return '';
        }
        $availability = new AdvancedAvailability;
        return $availability->getPropertyLabel($this->entry()->blueprint, $this->entry()->collection()->handle(), $this->property);
    }

    public function getEntryAttribute()
    {
        return Entry::find($this->item_id) ? Entry::find($this->item_id)->toAugmentedArray() : $this->emptyEntry();
    }

    public function options()
    {
        return $this->belongsToMany(Option::class, 'resrv_reservation_option')->withPivot('value')->withTrashed();
    }
    
    public function extras()
    {
        return $this->belongsToMany(Extra::class, 'resrv_reservation_extra')->withPivot('quantity')->withTrashed();
    }

    public function location_start_data()
    {
        return $this->hasOne(Location::class, 'id', 'location_start')->withTrashed();
    }
    
    public function location_end_data()
    {
        return $this->hasOne(Location::class, 'id', 'location_end')->withTrashed();
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
            'quantity' => $data['quantity'],
            'advanced' => $data['advanced'],
            'payment' => $data['payment'],
            'price' => $data['price'],
        ], $statamic_id);

        if ($checkAvailability == false) {
            throw new ReservationException(__('This item is not available anymore or the price has changed. Please refresh and try searching again!'));
        }

        $dbTotal = Price::create($this->confirmTotal($statamic_id, $data));
        $frontendTotal = Price::create($data['total']);

        if (! $dbTotal->equals($frontendTotal)) {
            throw new ReservationException(__('The price for that reservation has changed. Please refresh and try again!'));
        }

        if (! $this->checkForRequiredOptions($statamic_id, $data)) {
            throw new ReservationException(__('There are required options you did not select.'));
        }

        if ($data['quantity'] > config('resrv-config.maximum_quantity')) {
            throw new ReservationException(__('You cannot reserve these many in one reservation.'));
        }

        return true;

    }

    protected function confirmTotal($statamic_id, $data)
    {
        $reservationCost = Price::create($data['price']);

        $optionsCost = Price::create(0);
        if (array_key_exists('options', $data) > 0) {
            foreach($data['options'] as $id => $properties) {
                $optionsCost->add(Option::find($id)->calculatePrice($data, $properties['value']));
            }
        }    
        
        $extrasCost = Price::create(0);
        if (array_key_exists('extras', $data) > 0) {
            $data['item_id'] = $statamic_id;
            foreach($data['extras'] as $id => $properties) {
                $extrasCost->add(Extra::find($id)->calculatePrice($data, $properties['quantity']));
            }
        }    

        $locationCost = Price::create(0);
        if (config('resrv-config.enable_locations') == true) {            
            $locationCost->add(Location::find($data['location_start'])->extra_charge);
            $locationCost->add(Location::find($data['location_end'])->extra_charge);
            if (array_key_exists('quantity', $data) > 0) {
                $locationCost->multiply($data['quantity']);
            }
        }

        return $reservationCost->add($optionsCost, $extrasCost, $locationCost)->format();
    }

    protected function checkForRequiredOptions($statamic_id, $data)
    {        
        $requiredOptions = Option::entry($statamic_id)            
            ->where('published', true)
            ->where('required', true)
            ->get()
            ->groupBy('id')
            ->toArray();

        // If the item doesn't have required options return true
        if (count($requiredOptions) == 0) {
            return true;
        }

        // If the item has required options but the key is not present in the data array return false
        if (! array_key_exists('options', $data)) {
            return false;
        }

        $checkoutOptions = $data['options'];

        // Check if each required option is in the data array otherwise return false 
        foreach ($requiredOptions as $id => $option) {
            if (! array_key_exists($id, $checkoutOptions)) {
                return false;
            }
        }
        return true;
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

    public function expire($id)
    {
        try {
            DB::transaction(function() use ($id) {
                $reservation = $this->findOrFail($id);
                if ($reservation->status == 'pending') {
                    $reservation->status = 'expired';
                    $reservation->save();
                    ReservationExpired::dispatch($reservation);
                }
            });
        } catch (\Exception $e) {
        };
    }

    public function emptyEntry()
    {
        return [
            'title' => '## Entry deleted ##',
            'api_url' => '## Entry deleted ##',
            'permalink' => '#'
        ];        
    }

}
