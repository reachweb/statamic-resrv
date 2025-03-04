<?php

namespace Reach\StatamicResrv\Models;

use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Database\Factories\ReservationFactory;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Exceptions\ReservationException;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;

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
        'total' => PriceClass::class,
    ];

    protected $appends = ['entry'];

    protected static function newFactory()
    {
        return ReservationFactory::new();
    }

    public function entry()
    {
        // If this is a parent reservation with children, handle it specially
        if ($this->type === 'parent' && $this->childs()->count() > 0) {

        }

        return Entry::find($this->item_id) ?? $this->emptyEntry();
    }

    public function affiliate(): BelongsToMany
    {
        return $this->belongsToMany(Affiliate::class, 'resrv_reservation_affiliate')->withPivot('fee');
    }

    public function childs()
    {
        return $this->hasMany(ChildReservation::class);
    }

    public function dynamicPricings()
    {
        return $this->belongsToMany(DynamicPricing::class, 'resrv_reservation_dynamic_pricing')->withPivot('data');
    }

    public function getPriceAttribute($value)
    {
        return Price::create($value);
    }

    public function getPaymentAttribute($value)
    {
        return Price::create($value);
    }

    public function getTotalAttribute($value)
    {
        return Price::create($value);
    }

    public function getPropertyAttribute($value)
    {
        if ($this->type === 'parent') {
            return $this->childs()->get()->unique(fn ($item) => $item->property);
        }

        return $value;
    }

    public function getPropertyAttributeLabel()
    {
        if ($this->property == null) {
            return '';
        }
        $availability = new Availability;

        if ($this->property instanceof Collection) {
            return $this->property->map(function ($item) use ($availability) {
                return $availability->getPropertyLabel($this->entry()->blueprint, $this->entry()->collection()->handle(), $item->property);
            })->implode(',');
        }

        return $availability->getPropertyLabel($this->entry()->blueprint, $this->entry()->collection()->handle(), $this->property);
    }

    public function getEntryAttribute()
    {
        // If this is a parent reservation with children, return a special entry
        if ($this->type === 'parent' && $this->childs()->count() > 0) {
            return $this->parentFakeEntry();
        }

        $entry = Entry::find($this->item_id);

        return $entry ? $entry->toAugmentedArray(['id', 'title', 'slug', 'url']) : $this->emptyEntry();
    }

    public function options()
    {
        return $this->belongsToMany(Option::class, 'resrv_reservation_option')->withPivot('value')->withTrashed();
    }

    public function extras()
    {
        return $this->belongsToMany(Extra::class, 'resrv_reservation_extra')->withPivot(['quantity', 'price'])->withTrashed();
    }

    public function scopeFindByPaymentId($query, $id)
    {
        return $query->where('payment_id', $id);
    }

    public function isParent()
    {
        if ($this->type == 'parent') {
            return true;
        }

        return false;
    }

    public function amountRemaining()
    {
        return $this->total->subtract($this->payment)->format();
    }

    public function amountRemainingWithoutExtras()
    {
        return $this->price->subtract($this->payment)->subtract($this->extraCharges())->format();
    }

    public function duration()
    {
        return (int) $this->date_start->startOfDay()->diffInDays($this->date_end->startOfDay(), true);
    }

    public function extraCharges()
    {
        $extraCharges = Price::create(0);

        $data = $this->buildDataArray();
        $data['item_id'] = $this->item_id;

        $optionsCost = Price::create(0);
        if ($this->options()->count() > 0) {
            foreach ($this->options()->get() as $id => $option) {
                $optionsCost->add($option->calculatePrice($data, $option->pivot->value));
            }
        }

        $extrasCost = Price::create(0);
        if ($this->extras()->count() > 0) {
            foreach ($this->extras()->get() as $id => $extra) {
                $extrasCost->add($extra->calculatePrice($data, $extra->pivot->quantity));
            }
        }

        return $extraCharges->add($optionsCost, $extrasCost);
    }

    public function getPrices()
    {
        return (new Availability)->getPricing($this->buildDataArray(), $this->item_id);
    }

    public function validateReservation($data, $statamic_id, $checkExtras = true, $checkOptions = true)
    {
        $this->validateTotal($data, $statamic_id);

        $this->checkMaxQuantity($data['quantity']);

        if ($checkOptions && ! $this->checkForRequiredOptions($statamic_id, $data)) {
            throw new ReservationException(__('There are required options you did not select.'));
        }

        if ($checkExtras) {
            $requiredExtras = $this->checkForRequiredExtras($statamic_id, $data);
            if ($requiredExtras) {
                throw new ReservationException($requiredExtras);
            }
        }

        return true;
    }

    protected function buildDataArray()
    {
        return [
            'date_start' => $this->date_start,
            'date_end' => $this->date_end,
            'advanced' => $this->property,
            'quantity' => $this->quantity,
        ];
    }

    protected function checkMaxQuantity($quantity)
    {
        if ($quantity > config('resrv-config.maximum_quantity')) {
            throw new ReservationException(__('You cannot reserve these many in one reservation.'));
        }
    }

    protected function checkAvailability($data, $statamic_id)
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
    }

    public function validateTotal($data, $statamic_id)
    {
        $prices = (new Availability)->getPricing($data, $statamic_id);

        $reservationCost = Price::create($prices['price']);

        $dbTotal = $reservationCost->add($this->validateExtraCharges($data, $statamic_id));
        $frontendTotal = $data['total'];

        if (! $dbTotal->equals($frontendTotal)) {
            throw new ReservationException(__('The price for that reservation has changed. Please refresh and try again!'));
        }

        return true;
    }

    protected function validateExtraCharges($data, $statamic_id)
    {
        $extraCharges = Price::create(0);

        $optionsCost = Price::create(0);
        if (array_key_exists('options', $data) > 0) {
            $data['options']->each(function ($option) use ($data, $optionsCost) {
                $optionsCost->add(Option::find($option['id'])->calculatePrice($data, $option['value']));
            });
        }

        $extrasCost = Price::create(0);
        if (array_key_exists('extras', $data) > 0) {
            // The extra class needs the entry id to calculate the price
            $data['item_id'] = $statamic_id;
            $data['extras']->each(function ($extra) use ($data, $extrasCost) {
                $extrasCost->add(Extra::find($extra['id'])->calculatePrice($data, $extra['quantity']));
            });
        }

        return $extraCharges->add($optionsCost, $extrasCost);
    }

    protected function checkForRequiredExtras($statamic_id, $data)
    {
        $required = (new ExtraCondition)->hasRequiredExtrasSelected($statamic_id, $data);
        if ($required !== true) {
            return $required->transform(function ($messages, $extra_id) {
                return 'ID '.$extra_id.' '.$messages->implode(' ');
            })->implode(', ');
        }

        return false;
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

        // Convert checkoutOptions to array if it's a Laravel Collection
        if ($checkoutOptions instanceof Collection) {
            $checkoutOptions = $checkoutOptions->toArray();
        }

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

    // TODO: cleanup these methods
    public function getCheckoutForm()
    {
        $formHandle = $this->entry()->get('resrv_override_form') ?? config('resrv-config.form_name', 'checkout');

        return Form::find($formHandle)->fields()->values();
    }

    public function checkoutForm($entry = null)
    {
        $form = $this->getForm($entry);
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

    public function checkoutFormFieldsArray($entry = null)
    {
        $form = $this->getForm($entry);
        $fields = [];
        foreach ($form as $item) {
            $fields[$item->handle()] = $item->config()['display'];
        }

        return $fields;
    }

    protected function getForm($entry = null)
    {
        $formHandle = config('resrv-config.form_name', 'checkout');
        if ($entry) {
            $entry = Entry::find($entry);
            if ($entry->get('resrv_override_form')) {
                $formHandle = $entry->get('resrv_override_form');
            }
        }

        return Form::find($formHandle)->fields()->values();
    }

    public function getFormOptions()
    {
        $formHandle = config('resrv-config.form_name', 'checkout');
        if ($this->entry()->get('resrv_override_form')) {
            $formHandle = $this->entry()->get('resrv_override_form');
        }

        return Form::find($formHandle);
    }

    public function expire($id)
    {
        try {
            DB::transaction(function () use ($id) {
                $reservation = $this->findOrFail($id);
                if ($reservation->status == 'pending') {
                    $reservation->status = 'expired';
                    $reservation->save();
                    ReservationExpired::dispatch($reservation);
                }
            });
        } catch (\Exception $e) {
        }
    }

    public function emptyEntry()
    {
        return [
            'id' => null,
            'title' => '## Entry deleted ##',
            'api_url' => '## Entry deleted ##',
            'permalink' => '#',
        ];
    }

    protected function parentFakeEntry()
    {
        return [
            'id' => 'parent',
            'title' => 'Multiple Items',
            'url' => '#',
            'get' => null,
            'blueprint' => null,
            'collection' => [
                'handle' => 'parent',
            ],
        ];
    }

    public function getAllItems()
    {
        if ($this->type !== 'parent') {
            return collect([$this]);
        }

        return $this->childs()->with('reservation')->get()->map(function ($child) {
            return $child->reservation;
        });
    }
}
