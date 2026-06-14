<?php

namespace Reach\StatamicResrv\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Database\Factories\ReservationFactory;
use Reach\StatamicResrv\Enums\CancellationPolicy;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Enums\ReservationTypes;
use Reach\StatamicResrv\Events\ReservationExpired;
use Reach\StatamicResrv\Exceptions\ExtrasException;
use Reach\StatamicResrv\Exceptions\InvalidStateTransition;
use Reach\StatamicResrv\Exceptions\OptionsException;
use Reach\StatamicResrv\Exceptions\ReservationDriftException;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Money\Price as PriceClass;
use Reach\StatamicResrv\Support\CheckoutFormResolver;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

class Reservation extends Model
{
    use HasFactory;

    protected $table = 'resrv_reservations';

    // Explicit allow-list (vs. $guarded = []) so a future fill()/update() with untrusted input
    // can't mass-assign the primary key or any column not written by app code. Status changes
    // still go exclusively through transitionTo() (direct assignment, not mass assignment).
    // Timestamps stay fillable — time-based flows/tests set created_at/updated_at directly.
    protected $fillable = [
        'status',
        'type',
        'reference',
        'item_id',
        'date_start',
        'date_end',
        'quantity',
        'rate_id',
        'cancellation_policy',
        'free_cancellation_period',
        'price',
        'payment',
        'payment_surcharge',
        'payment_id',
        'payment_gateway',
        'total',
        'customer_id',
        'abandoned_email_sent_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'date_start' => 'datetime',
        'date_end' => 'datetime',
        // The money columns pair this cast with the get*Attribute accessors below on purpose —
        // see the accessor note. The cast normalises writes and powers string serialization;
        // do not drop a money cast without also removing its accessor.
        'price' => PriceClass::class,
        'payment' => PriceClass::class,
        'payment_surcharge' => PriceClass::class,
        'total' => PriceClass::class,
        'abandoned_email_sent_at' => 'datetime',
        'free_cancellation_period' => 'integer',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function affiliate(): BelongsToMany
    {
        return $this->belongsToMany(Affiliate::class, 'resrv_reservation_affiliate')->withPivot('fee');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(Rate::class, 'rate_id')->withTrashed();
    }

    public function childs()
    {
        return $this->hasMany(ChildReservation::class);
    }

    public function dynamicPricings()
    {
        return $this->belongsToMany(DynamicPricing::class, 'resrv_reservation_dynamic_pricing')->withPivot('data');
    }

    // These accessors are NOT redundant with the PriceClass cast above — the two serve different
    // reads. On direct access ($reservation->price) the accessor wins and returns a Price object
    // for money math (->format(), ->subtract(), ...). On array/JSON serialization the cast's get()
    // runs instead and emits a formatted string, which is what the ResrvCheckoutRedirect tag and
    // the gateway toArray() payloads expose to Antlers templates. Keep both halves in sync;
    // ReservationPriceCastTest locks this contract.
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

    public function getPaymentSurchargeAttribute($value)
    {
        return Price::create($value);
    }

    public function getRateSlugAttribute(): ?string
    {
        return $this->rate?->slug;
    }

    public function getCustomerDataAttribute(): Collection
    {
        if (! $this->customer_id) {
            return collect();
        }

        $customer = $this->customer;

        if (! $customer) {
            return collect();
        }

        $data = $customer->data;
        $data->put('email', $customer->email);

        return $data;
    }

    public function getRateLabel(): string
    {
        if ($this->isParent()) {
            return $this->childs
                ->map(fn ($child) => $child->getRateLabel())
                ->unique()
                ->implode(', ');
        }

        return $this->rate?->title ?? 'Default';
    }

    /**
     * The cancellation terms this reservation was booked under. Prefers the snapshot taken at
     * creation time (immune to later rate or config edits); reservations created before the
     * snapshot existed fall back to the live rate, then to the strictest policy across a
     * parent's children, then to the global config default.
     *
     * @return array{policy: CancellationPolicy, period: ?int}
     */
    public function effectiveCancellationPolicy(): array
    {
        if ($this->cancellation_policy) {
            $policy = CancellationPolicy::tryFrom($this->cancellation_policy) ?? CancellationPolicy::FreeCancellation;

            // Snapshots hold resolved values: non-refundable carries no period, an explicit
            // zero-day policy carries 0, and a booking made under the unconfigured global
            // default carries NULL (nothing to advertise) — don't cast NULL into "0 days".
            return [
                'policy' => $policy,
                'period' => $policy === CancellationPolicy::FreeCancellation ? $this->free_cancellation_period : null,
            ];
        }

        if ($this->rate) {
            return $this->rate->effectiveCancellationPolicy();
        }

        if ($this->isParent() && $this->childs->isNotEmpty()) {
            return static::strictestCancellationPolicy(
                $this->childs->loadMissing('rate')->map(fn ($child) => [
                    ...($child->rate?->effectiveCancellationPolicy() ?? CancellationPolicy::globalDefault()),
                    'date_start' => $child->date_start,
                ])
            );
        }

        return CancellationPolicy::globalDefault();
    }

    /**
     * Customer-facing label for the booked cancellation terms — used in the checkout
     * sidebar and the reservation emails.
     */
    public function cancellationPolicyLabel(): ?string
    {
        $cancellation = $this->effectiveCancellationPolicy();

        return CancellationPolicy::labelFor($cancellation['policy'], $cancellation['period'], $this->date_start);
    }

    /**
     * Pick the strictest policy from a set of selections: any non-refundable wins outright;
     * otherwise the one whose free-cancellation deadline (its own check-in date minus its
     * period) falls earliest. Periods belonging to different check-in dates are not directly
     * comparable, so the winning deadline is converted back into a period relative to the
     * earliest check-in in the set — the date a parent reservation stores as date_start.
     *
     * @param  Collection<int, array{policy: CancellationPolicy, period: ?int, date_start: mixed}>  $policies
     * @return array{policy: CancellationPolicy, period: ?int}
     */
    public static function strictestCancellationPolicy(Collection $policies): array
    {
        if ($policies->isEmpty()) {
            return CancellationPolicy::globalDefault();
        }

        if ($policies->contains(fn ($policy) => $policy['policy'] === CancellationPolicy::NonRefundable)) {
            return ['policy' => CancellationPolicy::NonRefundable, 'period' => null];
        }

        // All-NULL periods means nothing was configured anywhere — keep the NULL so the
        // parent stays as unadvertised as a single unconfigured booking would be.
        if ($policies->every(fn ($policy) => $policy['period'] === null)) {
            return ['policy' => CancellationPolicy::FreeCancellation, 'period' => null];
        }

        $checkIns = $policies->map(fn ($policy) => Carbon::parse($policy['date_start'])->startOfDay());
        $deadlines = $policies->map(
            fn ($policy) => Carbon::parse($policy['date_start'])->startOfDay()->subDays((int) $policy['period'])
        );

        return [
            'policy' => CancellationPolicy::FreeCancellation,
            'period' => (int) round($deadlines->min()->diffInDays($checkIns->min(), true)),
        ];
    }

    public function getPropertyAttributeLabel(): string
    {
        return $this->getRateLabel();
    }

    public function getEntryAttribute()
    {
        return $this->entryToArray(Entry::find($this->item_id));
    }

    /**
     * Accepts a pre-resolved entry so CP resources can batch all lookups via resolveReservationEntries()
     * instead of one Entry::find() per row.
     */
    public function entryToArray(?EntryContract $entry): array
    {
        return $entry ? $entry->toAugmentedArray(['id', 'title', 'slug', 'url']) : $this->emptyEntry();
    }

    public function options()
    {
        return $this->belongsToMany(Option::class, 'resrv_reservation_option')->withPivot(['value', 'value_name', 'price', 'price_type'])->withTrashed();
    }

    public function extras()
    {
        return $this->belongsToMany(Extra::class, 'resrv_reservation_extra')->withPivot(['quantity', 'price'])->withTrashed();
    }

    public function surcharges()
    {
        return $this->belongsToMany(Surcharge::class, 'resrv_reservation_surcharge')->withPivot(['name', 'price'])->withTrashed();
    }

    public function scopeFindByPaymentId($query, $id)
    {
        // payment_id is cleared to '' on expire/cancel, so a falsy id must match nothing rather than
        // every cleared reservation.
        return $query->where('payment_id', $id)
            ->where('payment_id', '!=', '')
            ->whereNotNull('payment_id');
    }

    /**
     * Returns true when the status changed, false on same-state no-op. Callers MUST gate
     * event dispatch on the return value to prevent duplicate side effects (e.g. double
     * IncreaseAvailability). Events are not dispatched here so callers can choose which event
     * to fire (e.g. PARTNER still triggers ReservationConfirmed).
     *
     * Pass $tolerant = true for reactive callers (webhooks/checkout) so a concurrent update that
     * changed the row after their in-memory pre-check is treated as a no-op (returns false) rather
     * than throwing — avoiding a spurious HTTP 500 / webhook retry. Explicit callers (CP refund)
     * keep the default and catch InvalidStateTransition to surface the error.
     */
    public function transitionTo(ReservationStatus $to, bool $tolerant = false): bool
    {
        return (bool) DB::transaction(function () use ($to, $tolerant) {
            $fresh = static::query()->lockForUpdate()->findOrFail($this->id);

            if ($fresh->status === $to->value) {
                return false;
            }

            $current = ReservationStatus::from($fresh->status);

            if (! $current->canTransitionTo($to)) {
                if ($tolerant) {
                    Log::info('Skipped reservation transition; state changed under lock.', [
                        'reservation_id' => $fresh->id,
                        'from' => $current->value,
                        'to' => $to->value,
                    ]);

                    return false;
                }

                throw new InvalidStateTransition($current, $to, $fresh->id);
            }

            $fresh->status = $to->value;
            $fresh->save();

            $this->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });
    }

    public function isParent(): bool
    {
        return $this->type === 'parent';
    }

    public function amountRemaining()
    {
        // Remaining balance excludes whatever is due now (deposit + the always-now booking surcharge).
        // $this->total is a fresh Price from the accessor, so subtracting in place is safe.
        return $this->total->subtract($this->payableNow())->format();
    }

    public function totalToCharge()
    {
        // What the gateway charges: the amount due now plus any payment-gateway surcharge.
        return $this->payableNow()->add($this->payment_surcharge)->format();
    }

    public function amountRemainingWithoutExtras()
    {
        return $this->price->subtract($this->payment)->subtract($this->extraCharges())->format();
    }

    public function duration()
    {
        // Copy before startOfDay() — date_start/date_end are mutable Carbon instances, so mutating
        // them in place is a footgun (and inconsistent with HandlesAvailabilityDates::checkMinimumDate).
        return (int) $this->date_start->copy()->startOfDay()->diffInDays($this->date_end->copy()->startOfDay(), true);
    }

    public function extraCharges()
    {
        if ($this->isParent()) {
            return $this->parentExtraCharges();
        }

        $data = $this->buildDataArray();
        $data['item_id'] = $this->item_id;

        $optionsCost = Price::create(0);
        foreach ($this->options()->get() as $option) {
            $optionsCost->add($this->resolveOptionPrice($option, $data));
        }

        $extrasCost = Price::create(0);
        foreach ($this->extras()->get() as $extra) {
            $extrasCost->add($extra->calculatePrice($data, $extra->pivot->quantity));
        }

        $surchargeCost = $this->bookingSurchargeTotal();

        return Price::create(0)->add($optionsCost, $extrasCost, $surchargeCost);
    }

    protected function parentExtraCharges()
    {
        $totalCharges = Price::create(0);

        // Options snapshot the full per-reservation price at checkout (already aggregated across all
        // children by getOptionsWithParentPricing), so add each once — NOT once per child. Pre-snapshot
        // reservations (null price) fall back to the legacy per-child sum in the loop below.
        foreach ($this->options()->get() as $option) {
            if ($option->pivot->price !== null) {
                $totalCharges->add(Price::create($option->pivot->price));
            }
        }

        foreach ($this->childs as $child) {
            $data = $this->buildChildDataArray($child);

            // Legacy fallback only: options without a snapshot are summed per child. Fresh instances
            // per child — OptionValue::calculatePrice mutates $this->price via Price::multiply().
            foreach ($this->options()->with('values')->get() as $option) {
                if ($option->pivot->price === null) {
                    $totalCharges->add($option->calculatePrice($data, $option->pivot->value));
                }
            }

            foreach ($this->extras()->get() as $extra) {
                // Fresh instance via withTrashed(): Extra::calculatePrice mutates price,
                // and soft-deleted extras on historical reservations must still be priced.
                $totalCharges->add(Extra::withTrashed()->find($extra->id)->calculatePrice($data, $extra->pivot->quantity));
            }
        }

        // Flat surcharge once per reservation, from the checkout snapshot — NOT per child.
        $totalCharges->add($this->bookingSurchargeTotal());

        return $totalCharges;
    }

    /**
     * The option's charged price: the value snapshotted onto the pivot at checkout, or a live
     * re-price for pre-snapshot (historical) reservations whose pivot predates the snapshot columns.
     */
    protected function resolveOptionPrice(Option $option, array $data): PriceClass
    {
        if ($option->pivot->price !== null) {
            return Price::create($option->pivot->price);
        }

        return $option->calculatePrice($data, $option->pivot->value);
    }

    /**
     * Map of [optionId => selectedValueId] from a checkout data payload, for evaluating surcharges
     * during the drift check before anything is persisted.
     *
     * @return array<int, int>
     */
    protected function optionSelectionsFromData($data): array
    {
        if (! array_key_exists('options', $data)) {
            return [];
        }

        return collect($data['options'])
            ->mapWithKeys(fn ($option) => [$option['id'] => $option['value']])
            ->all();
    }

    /**
     * Total of the surcharges snapshotted onto this reservation (frozen at checkout).
     */
    public function bookingSurchargeTotal(): PriceClass
    {
        $total = Price::create(0);

        // Use the cached relation (not ->get()): payableNow/amountRemaining/totalToCharge/extraCharges
        // each call this within a single render, and CP show eager-loads surcharges.
        foreach ($this->surcharges as $surcharge) {
            $total->add(Price::create($surcharge->pivot->price));
        }

        return $total;
    }

    /**
     * The amount due now, excluding any payment-gateway surcharge. Full-payment reservations already
     * fold the booking surcharge into `payment` (== `total`); deposit reservations keep `payment` as
     * the base deposit, so the surcharge — always due now — is added on top.
     */
    public function payableNow(): PriceClass
    {
        if ($this->payment->equals($this->total)) {
            return Price::create($this->payment->format());
        }

        return Price::create($this->payment->format())->add($this->bookingSurchargeTotal());
    }

    public function getPrices()
    {
        return (new Availability)->getPricing($this->buildDataArray(), $this->item_id);
    }

    public function validateReservation($data, $statamic_id, $checkExtras = true, $checkOptions = true)
    {
        $this->validateTotal($data, $statamic_id);

        if ($this->type === ReservationTypes::PARENT->value) {
            $this->checkMaxQuantity($this->childs->sum('quantity'));
        } else {
            $this->checkMaxQuantity($data['quantity']);
        }

        if ($checkOptions && ! $this->checkForRequiredOptions($statamic_id, $data)) {
            throw new OptionsException(__('There are required options you did not select.'));
        }

        if ($checkExtras) {
            $requiredExtras = $this->checkForRequiredExtras($statamic_id, $data);
            if ($requiredExtras) {
                throw new ExtrasException($requiredExtras);
            }
        }

        return true;
    }

    protected function buildDataArray()
    {
        return [
            'date_start' => $this->date_start,
            'date_end' => $this->date_end,
            'quantity' => $this->quantity,
            'rate_id' => $this->rate_id,
        ];
    }

    protected function buildChildDataArray(ChildReservation $child): array
    {
        return [
            'date_start' => $child->date_start,
            'date_end' => $child->date_end,
            'quantity' => $child->quantity,
            'rate_id' => $child->rate_id,
            'item_id' => $this->item_id,
            'customer' => $this->customerData,
        ];
    }

    protected function checkMaxQuantity($quantity)
    {
        if ($quantity > config('resrv-config.maximum_quantity')) {
            throw new ReservationDriftException(__('You cannot reserve these many in one reservation.'));
        }
    }

    protected function checkAvailability($data, $statamic_id)
    {
        $availability = new Availability;

        $checkAvailability = $availability->confirmAvailabilityAndPrice([
            'date_start' => $data['date_start'],
            'date_end' => $data['date_end'],
            'quantity' => $data['quantity'],
            'rate_id' => $data['rate_id'] ?? null,
            'payment' => $data['payment'],
            'price' => $data['price'],
        ], $statamic_id);

        if ($checkAvailability == false) {
            throw new ReservationDriftException(__('This item is not available anymore or the price has changed. Please refresh and try searching again!'));
        }
    }

    public function validateTotal($data, $statamic_id)
    {
        if ($this->type === ReservationTypes::PARENT->value) {
            $reservationCost = Price::create(0);
            foreach ($this->childs as $child) {
                $childPrices = (new Availability)->getPricing([
                    'date_start' => $child->date_start,
                    'date_end' => $child->date_end,
                    'quantity' => $child->quantity,
                    'rate_id' => $child->rate_id,
                ], $statamic_id);
                if ($childPrices === false) {
                    throw new ReservationDriftException(__('This item is not available anymore or the price has changed. Please refresh and try searching again!'));
                }
                $reservationCost->add(Price::create($childPrices['price']));
            }
        } else {
            $prices = (new Availability)->getPricing($data, $statamic_id);
            if ($prices === false) {
                throw new ReservationDriftException(__('This item is not available anymore or the price has changed. Please refresh and try searching again!'));
            }
            $reservationCost = Price::create($prices['price']);
        }

        $dbTotal = $reservationCost->add($this->validateExtraCharges($data, $statamic_id));
        $frontendTotal = $data['total'];

        if (! $dbTotal->equals($frontendTotal)) {
            throw new ReservationDriftException(__('The price for that reservation has changed. Please refresh and try again!'));
        }

        return true;
    }

    protected function validateExtraCharges($data, $statamic_id)
    {
        if ($this->isParent()) {
            return $this->validateParentExtraCharges($data, $statamic_id);
        }

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

        $surchargeCost = Surcharge::totalForSelections($this->optionSelectionsFromData($data));

        return $extraCharges->add($optionsCost, $extrasCost, $surchargeCost);
    }

    protected function validateParentExtraCharges($data, $statamic_id)
    {
        $totalCharges = Price::create(0);

        foreach ($this->childs as $child) {
            $childData = $this->buildChildDataArray($child);
            if (array_key_exists('customer', $data)) {
                $childData['customer'] = $data['customer'];
            }

            if (array_key_exists('options', $data) && $data['options']->count() > 0) {
                $data['options']->each(function ($option) use ($childData, $totalCharges) {
                    $totalCharges->add(Option::find($option['id'])->calculatePrice($childData, $option['value']));
                });
            }

            if (array_key_exists('extras', $data) && $data['extras']->count() > 0) {
                $data['extras']->each(function ($extra) use ($childData, $totalCharges) {
                    $totalCharges->add(Extra::find($extra['id'])->calculatePrice($childData, $extra['quantity']));
                });
            }
        }

        // Flat surcharge once per reservation — NOT per child.
        $totalCharges->add(Surcharge::totalForSelections($this->optionSelectionsFromData($data)));

        return $totalCharges;
    }

    protected function checkForRequiredExtras($statamic_id, $data)
    {
        if ($this->isParent()) {
            return $this->checkForRequiredExtrasForParent($statamic_id, $data);
        }

        $required = (new ExtraCondition)->hasRequiredExtrasSelected($statamic_id, $data);
        if ($required !== true) {
            return $required->transform(function ($messages, $extra_id) {
                return 'ID '.$extra_id.' '.$messages->implode(' ');
            })->implode(', ');
        }

        return false;
    }

    protected function checkForRequiredExtrasForParent($statamic_id, $data)
    {
        $allRequired = collect();
        $extraCondition = new ExtraCondition;

        foreach ($this->childs as $child) {
            $childData = array_merge($data, [
                'date_start' => $child->date_start,
                'date_end' => $child->date_end,
                'quantity' => $child->quantity,
                'rate_id' => $child->rate_id,
            ]);

            $required = $extraCondition->hasRequiredExtrasSelected($statamic_id, $childData);
            if ($required !== true) {
                // union() preserves integer keys (real DB ids); first-wins on collision is fine.
                $allRequired = $allRequired->union($required);
            }
        }

        if ($allRequired->isEmpty()) {
            return false;
        }

        return $allRequired->transform(function ($messages, $extra_id) {
            return 'ID '.$extra_id.' '.$messages->implode(' ');
        })->implode(', ');
    }

    protected function checkForRequiredOptions($statamic_id, $data): bool
    {
        $disabledValueIds = OptionValue::disabledIdsForEntry($statamic_id);

        $requiredOptionIds = Option::entry($statamic_id)
            ->where('published', true)
            ->where('required', true)
            ->with('values')
            ->get()
            ->filter(function ($option) use ($disabledValueIds) {
                // A required option whose values are all disabled for this entry cannot be required —
                // otherwise checkout would demand a selection the UI never renders (deadlock).
                return $option->values->reject(fn ($value) => in_array($value->id, $disabledValueIds))->isNotEmpty();
            })
            ->pluck('id');

        if ($requiredOptionIds->isEmpty()) {
            return true;
        }

        if (! array_key_exists('options', $data)) {
            return false;
        }

        $checkoutOptions = $data['options'] instanceof Collection
            ? $data['options']->toArray()
            : $data['options'];

        return $requiredOptionIds->every(fn ($id) => array_key_exists($id, $checkoutOptions));
    }

    public function createRandomReference()
    {
        return Str::upper(Str::random(6));
    }

    // TODO: cleanup these methods
    public function getCheckoutForm()
    {
        /** @var CheckoutFormResolver $resolver */
        $resolver = app(CheckoutFormResolver::class);

        return $resolver->resolveForReservation($this)->fields()->values();
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
        /** @var CheckoutFormResolver $resolver */
        $resolver = app(CheckoutFormResolver::class);

        if ($entry) {
            $entryId = $entry instanceof EntryContract ? (string) $entry->id() : (string) $entry;

            return $resolver->resolveForEntryId($entryId)->fields()->values();
        }

        return $resolver->resolveForReservation($this)->fields()->values();
    }

    public function getFormOptions()
    {
        /** @var CheckoutFormResolver $resolver */
        $resolver = app(CheckoutFormResolver::class);

        return $resolver->resolveForReservation($this);
    }

    /**
     * Clears payment_id/payment_gateway before commit (blocks racing webhooks), then cancels
     * the remote intent after commit (no lock held across the network call). If remote cancel
     * fails, local state is already correct; the error is logged for manual reconciliation.
     */
    public function expire(): void
    {
        $oldPaymentId = null;
        $oldGateway = null;
        $expired = null;

        DB::transaction(function () use (&$oldPaymentId, &$oldGateway, &$expired) {
            $reservation = static::query()->lockForUpdate()->findOrFail($this->id);

            if ($reservation->status !== ReservationStatus::PENDING->value) {
                return;
            }

            $oldPaymentId = $reservation->payment_id !== '' ? $reservation->payment_id : null;
            $oldGateway = $reservation->payment_gateway !== '' ? $reservation->payment_gateway : null;

            $reservation->payment_id = '';
            $reservation->payment_gateway = '';
            $reservation->status = ReservationStatus::EXPIRED->value;
            $reservation->save();

            $this->setRawAttributes($reservation->getAttributes(), true);
            $expired = $reservation;
        });

        if ($expired === null) {
            return;
        }

        if ($oldPaymentId !== null && $oldGateway !== null) {
            try {
                app(PaymentGatewayManager::class)
                    ->gateway($oldGateway)
                    ->cancelPaymentIntent($oldPaymentId, $expired);
            } catch (\Throwable $e) {
                Log::error('Failed to cancel payment intent during expiration; manual reconciliation may be required.', [
                    'reservation_id' => $expired->id,
                    'payment_id' => $oldPaymentId,
                    'payment_gateway' => $oldGateway,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        ReservationExpired::dispatch($expired);
    }

    public function emptyEntry()
    {
        // Matches the shape returned by entryToArray(); null url renders as plain text, not a broken link.
        return [
            'id' => null,
            'title' => '## Entry deleted ##',
            'slug' => null,
            'url' => null,
        ];
    }
}
