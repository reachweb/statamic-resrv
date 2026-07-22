<?php

namespace Reach\StatamicResrv\Models;

use Carbon\Carbon;
use Closure;
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
use Reach\StatamicResrv\Exceptions\UnknownPaymentGateway;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
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
        'payment_unresolved',
        'total',
        'customer_id',
        'abandoned_email_sent_at',
        'affects_availability',
        'created_by',
        'hold_expires_at',
        'payment_request_email_sent_at',
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
        'affects_availability' => 'boolean',
        'payment_unresolved' => 'boolean',
        'hold_expires_at' => 'datetime',
        'payment_request_email_sent_at' => 'datetime',
    ];

    // Mirrors the DB default so a model create()d without the flag (frontend checkout) reads
    // true — availability listeners consult the dispatching instance before any DB refresh.
    protected $attributes = [
        'affects_availability' => true,
    ];

    protected $appends = ['entry'];

    /**
     * The source status observed under the row lock by the last transitionTo() call that
     * changed state. This is the accurate "from" for activity logging — a caller's pre-check
     * read can go stale while a concurrent writer (e.g. a webhook confirm racing a CP refund)
     * moves the row before the lock is taken.
     */
    public ?ReservationStatus $lastTransitionFrom = null;

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
        return $this->belongsToMany(Affiliate::class, 'resrv_reservation_affiliate')->withPivot('fee', 'source', 'cancelled_at');
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
     * Last day the customer may cancel free of charge (inclusive through end of day). NULL
     * when non-refundable or no period was configured.
     */
    public function freeCancellationDeadline(): ?Carbon
    {
        $cancellation = $this->effectiveCancellationPolicy();

        if ($cancellation['policy'] !== CancellationPolicy::FreeCancellation || $cancellation['period'] === null) {
            return null;
        }

        return CancellationPolicy::deadlineFor($this->date_start, $cancellation['period']);
    }

    /**
     * Confirmed (directly or via partner flow) and not yet terminal. PENDING rows are
     * mid-checkout and excluded.
     */
    public function isLive(): bool
    {
        return in_array($this->status, ReservationStatus::live(), true);
    }

    /** Admin-created (manual) reservation still waiting for the customer to pay. */
    public function isAwaitingPayment(): bool
    {
        return $this->status === ReservationStatus::AWAITING_PAYMENT->value;
    }

    /**
     * The lapse sweep may not have cancelled the row yet, so payment surfaces must
     * refuse on this check rather than trust the status alone.
     */
    public function holdDeadlinePassed(): bool
    {
        return $this->hold_expires_at !== null && $this->hold_expires_at->isPast();
    }

    /**
     * Whether the customer may self-cancel at all — with a refund inside the free
     * cancellation window, or without one after it closes.
     */
    public function canBeCancelledByCustomer(): bool
    {
        return $this->canCancelWithRefund() || $this->canCancelWithoutRefund();
    }

    /**
     * Whether the customer may self-cancel with automatic full refund: live, within an
     * unexpired free-cancellation window, not after a timed check-in has begun, and the
     * money can flow back without manual work.
     */
    public function canCancelWithRefund(): bool
    {
        if (! $this->isLive()) {
            return false;
        }

        $deadline = $this->freeCancellationDeadline();

        if ($deadline === null || now()->gt($deadline->endOfDay())) {
            return false;
        }

        if ($this->timedCheckInHasStarted()) {
            return false;
        }

        return $this->supportsAutomaticRefund();
    }

    /**
     * Whether a real check-in moment has already passed. Only bookings whose date_start
     * carries a time-of-day are judged: the standard flow stores date-only starts (midnight),
     * where "check-in has begun" is meaningless on the arrival day and would wrongly close
     * the deliberate zero-day arrival-day refund window. For timed bookings the window must
     * cap at the start moment — a zero-day policy keeps the refund cancel open through the
     * end of the arrival day, which would otherwise let an already-started stay self-refund.
     */
    protected function timedCheckInHasStarted(): bool
    {
        if ($this->date_start->copy()->startOfDay()->eq($this->date_start)) {
            return false;
        }

        return ! now()->lt($this->date_start);
    }

    /**
     * Whether the customer may self-cancel WITHOUT a refund: a live booking paid online
     * through a gateway that could refund automatically, before the stay starts, once the
     * free-cancellation window has closed (or the policy is non-refundable). The payment
     * stays with the business — so the forfeit must stem from terms the customer actually
     * agreed to: a NonRefundable policy, or a free-cancellation window that existed and
     * closed. A NULL period means no policy was ever configured (nothing was advertised at
     * checkout), which fails closed to "contact us" rather than silently forfeiting the
     * payment. hasGatewayPayment() keeps partner and zero-charge bookings out, and
     * supportsAutomaticRefund() keeps offline/manual and no-longer-configured gateways
     * out — all of those are "contact us to cancel" cases by design.
     */
    public function canCancelWithoutRefund(): bool
    {
        if (! $this->isLive() || $this->canCancelWithRefund()) {
            return false;
        }

        $policy = $this->effectiveCancellationPolicy()['policy'];

        if ($policy !== CancellationPolicy::NonRefundable && $this->freeCancellationDeadline() === null) {
            return false;
        }

        if (! now()->lt($this->date_start)) {
            return false;
        }

        return $this->hasGatewayPayment() && $this->supportsAutomaticRefund();
    }

    /**
     * Whether cancelling can return the money without manual work: no charge reached a
     * gateway, or the gateway supports API refunds. Offline gateways (bank transfer) cannot,
     * and neither can a recorded gateway that is no longer configured — nobody can move
     * that money automatically, so such reservations require manual handling.
     */
    public function supportsAutomaticRefund(): bool
    {
        if ($this->gatewayHoldsNoCharge()) {
            return true;
        }

        // An out-of-band confirmation (CONFIRMED, no charge reference) collected money outside any
        // gateway — a self-service refund would land REFUNDED without money moving. Route to manual
        // handling; the CP refund action doesn't go through this method.
        if ($this->confirmedWithoutGatewayCharge()) {
            return false;
        }

        try {
            return $this->resolvePaymentGateway()->supportsAutomaticRefunds();
        } catch (UnknownPaymentGateway) {
            return false;
        }
    }

    /**
     * Whether the gateway is an offline one an admin confirms manually. An unknown gateway
     * reports false so nothing offers offline instructions it can't back up.
     */
    public function paymentGatewaySupportsManualConfirmation(): bool
    {
        try {
            return $this->resolvePaymentGateway()->supportsManualConfirmation();
        } catch (UnknownPaymentGateway) {
            return false;
        }
    }

    /**
     * True when no charge exists to refund: empty payment_id AND (partner or zero payment).
     * Other empty-payment_id rows (e.g. PENDING with an orphaned charge) are assumed to hold one.
     */
    public function gatewayHoldsNoCharge(): bool
    {
        return ($this->payment_id === '' || $this->payment_id === null)
            && ($this->status === ReservationStatus::PARTNER->value || $this->payment->isZero());
    }

    /**
     * The gateway this reservation was paid through. Only legacy rows with a BLANK recorded
     * gateway fall back to the default; a non-empty gateway that is no longer configured
     * fails closed with UnknownPaymentGateway — silently substituting the current default
     * would hand this reservation's foreign payment_id to a provider that never charged it.
     */
    public function resolvePaymentGateway(): PaymentInterface
    {
        $manager = app(PaymentGatewayManager::class);

        try {
            return $manager->forReservation($this);
        } catch (UnknownPaymentGateway $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            if (blank($this->payment_gateway)) {
                return $manager->gateway();
            }

            throw new UnknownPaymentGateway($this->payment_gateway, $this->id, $e);
        }
    }

    /**
     * Whether a live booking's free-cancellation window has closed — used to explain the
     * missing cancel button.
     */
    public function freeCancellationExpired(): bool
    {
        $deadline = $this->freeCancellationDeadline();

        return $this->isLive() && $deadline !== null && now()->gt($deadline->endOfDay());
    }

    /**
     * Whether a payment intent ever reached a gateway (payment_id non-empty). Unlike
     * gatewayHoldsNoCharge() it ignores status, so it stays correct after REFUNDED.
     */
    public function hasGatewayPayment(): bool
    {
        return $this->payment_id !== '' && $this->payment_id !== null;
    }

    /**
     * CONFIRMED with no charge reference — only possible via an out-of-band/manual confirmation
     * (a webhook confirm always stores the intent id), so a refund must skip the provider.
     */
    public function confirmedWithoutGatewayCharge(): bool
    {
        return $this->status === ReservationStatus::CONFIRMED->value
            && ($this->payment_id === '' || $this->payment_id === null);
    }

    /**
     * Whether refunding this reservation returns the money automatically through the gateway —
     * true only when a charge actually reached a gateway AND that gateway supports API refunds.
     * False for offline/manual gateways (an admin must return the funds by hand) and for no-charge
     * bookings (nothing to return). Drives the "refunded" vs "refund manually" wording in the
     * customer and admin cancellation emails.
     */
    public function refundIsAutomatic(): bool
    {
        return $this->hasGatewayPayment() && $this->supportsAutomaticRefund();
    }

    /**
     * Amount actually collected: payment + payment_surcharge (the sum the intent charged and
     * the webhook verifies). Independent of current payment-mode config, which would misreport
     * bookings made before a mode change. Zero when no charge reached a gateway.
     */
    public function amountPaid(): PriceClass
    {
        if (! $this->hasGatewayPayment()) {
            return Price::create(0);
        }

        return $this->payment->add($this->payment_surcharge);
    }

    /**
     * Amount a refund returns — the full intent, i.e. exactly what was collected.
     */
    public function refundedAmount(): PriceClass
    {
        return $this->amountPaid();
    }

    /**
     * What the customer owes: payment + payment_surcharge — the sum the intent charges and the
     * webhook's amount guard verifies. Fresh Price because add() mutates the cached cast instance.
     */
    public function amountDue(): PriceClass
    {
        return Price::create($this->payment->format())->add($this->payment_surcharge);
    }

    /**
     * Commission owed to the given affiliate for this reservation (total × pivot fee %), zero once
     * the commission has been cancelled. Single owner of the formula so the CP serializer and the
     * CSV export can never drift apart.
     */
    public function affiliateCommissionFor(Affiliate $affiliate): PriceClass
    {
        if ($affiliate->pivot->cancelled_at !== null) {
            return Price::create(0);
        }

        return $this->total->multiply((float) $affiliate->pivot->fee / 100);
    }

    /**
     * Amount the customer actually paid online at checkout, for customer-facing surfaces.
     * Manual-confirmation gateways (offline / bank transfer) mint a payment_id without
     * collecting any money — the funds arrive out-of-band — so amountPaid() would overstate
     * them as paid. refundedAmount() intentionally keeps reporting the full deposit so the
     * admin "refund manually" path still shows what to return.
     */
    public function amountPaidOnline(): PriceClass
    {
        // An unresolved reference is a reconciliation handle, not verified collected money.
        if (! $this->hasGatewayPayment() || $this->payment_unresolved) {
            return Price::create(0);
        }

        try {
            if ($this->resolvePaymentGateway()->supportsManualConfirmation()) {
                return Price::create(0);
            }
        } catch (UnknownPaymentGateway) {
            // Display-only: with the gateway gone we cannot ask it whether it collected
            // online, so report the amount the reservation itself recorded rather than
            // breaking the customer status page.
        }

        return $this->amountPaid();
    }

    /**
     * App-key HMAC binding the reservation key to the customer email — proves a lookup link
     * came from an email we sent, without exposing the address in the URL. Signing the key
     * (not the email alone) scopes the link to this single booking, so a leaked link can't be
     * replayed against the same customer's other reservations by swapping the reference.
     */
    public function customerLookupHash(): ?string
    {
        $email = $this->customer?->email;

        if ($email === null || $this->getKey() === null) {
            return null;
        }

        return hash_hmac('sha256', $this->getKey().'|'.$email, config('app.key'));
    }

    /**
     * Absolute "manage your booking" deep link to the reservation-status page, or null when the
     * status page entry is unconfigured/missing/unpublished/unroutable or the reservation has no
     * customer email to authenticate with. Carries the reference plus customerLookupHash() so the
     * page opens (and offers cancellation) without the customer re-entering anything.
     */
    public function customerStatusUrl(): ?string
    {
        // The whole customer status feature is opt-in; without the toggle the emails must
        // not link to a page whose component renders nothing.
        if (! config('resrv-config.enable_reservation_status_page')) {
            return null;
        }

        return $this->customerPageUrl(config('resrv-config.reservation_status_entry'));
    }

    /**
     * Absolute deep link to the pay page, or null when the entry doesn't resolve, there is no
     * customer email, or the reservation is no longer awaiting payment. No feature toggle —
     * configuring the entry IS the opt-in (unconfigured disables online gateways in the CP).
     */
    public function customerPaymentUrl(): ?string
    {
        if (! $this->isAwaitingPayment()) {
            return null;
        }

        return $this->customerPageUrl(config('resrv-config.manual_reservations_payment_entry'));
    }

    /**
     * Resolve a customer-page config value to its routable entry, or null. Single owner of
     * "usable" — the CP gateway gating and the emailed links must never disagree.
     */
    public static function resolveCustomerPageEntry(mixed $entryId): ?EntryContract
    {
        // The entries fieldtype may surface its single value as a one-element array.
        if (is_array($entryId)) {
            $entryId = $entryId[0] ?? null;
        }

        // Eloquent-driver sites can store integer entry IDs, so accept any non-empty scalar.
        if (! is_string($entryId) && ! is_int($entryId)) {
            return null;
        }

        $entryId = (string) $entryId;

        if ($entryId === '') {
            return null;
        }

        $entry = Entry::find($entryId);

        // url() ignores publish state, so check it explicitly — a draft or private page
        // must hide the button rather than email a link that 404s for guests.
        if (! $entry || ! $entry->published() || $entry->private() || ! $entry->url()) {
            return null;
        }

        return $entry;
    }

    /**
     * Shared builder for customer deep links: the entry must resolve (resolveCustomerPageEntry())
     * and the reservation must carry a reference + customer email for the ?ref=&hash= pair.
     */
    protected function customerPageUrl(mixed $entryId): ?string
    {
        $hash = $this->customerLookupHash();

        if ($hash === null || ! $this->reference) {
            return null;
        }

        $entry = static::resolveCustomerPageEntry($entryId);

        if (! $entry) {
            return null;
        }

        return $entry->absoluteUrl().'?'.http_build_query([
            'ref' => $this->reference,
            'hash' => $hash,
        ]);
    }

    /**
     * Find a reservation by reference code + booking email (case/whitespace tolerant). Email is
     * part of selection, not a post-check, because the reference column is not unique.
     */
    public static function findByReferenceForCustomer(string $reference, string $email, array $statuses): ?self
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return null;
        }

        return static::customerLookupCandidates($reference, $statuses)
            ->first(fn (self $reservation) => $reservation->customer?->email
                && hash_equals(strtolower($reservation->customer->email), $email));
    }

    /**
     * Resolve a customer deep link (reference + customerLookupHash()) to its reservation, or
     * null. The hash disambiguates reservations sharing a reference, like email above.
     */
    public static function findForCustomerLookup(string $reference, string $hash, array $statuses): ?self
    {
        $match = static::customerLookupCandidates($reference, $statuses)
            ->first(function (self $reservation) use ($hash) {
                $expectedHash = $reservation->customerLookupHash();

                return $expectedHash !== null && hash_equals($expectedHash, $hash);
            });

        // Dummy HMAC on the no-candidate branch so it isn't trivially faster than one with rows
        // to compare. Not constant-time, and need not be: the reference isn't the secret — the
        // app-key HMAC is, compared with hash_equals above.
        if ($match === null) {
            hash_equals(hash_hmac('sha256', 'resrv-missing-reservation', config('app.key')), $hash);

            return null;
        }

        return $match;
    }

    /**
     * All visible reservations matching a reference code (column isn't unique; callers
     * disambiguate by credential). Ordered by id so duplicates resolve deterministically.
     */
    protected static function customerLookupCandidates(string $reference, array $statuses): Collection
    {
        $reference = strtoupper(trim($reference));

        if ($reference === '') {
            return collect();
        }

        return static::query()
            ->with('customer')
            ->where('reference', $reference)
            ->whereIn('status', $statuses)
            ->orderBy('id')
            ->get();
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
            fn ($policy) => CancellationPolicy::deadlineFor(Carbon::parse($policy['date_start']), (int) $policy['period'])
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
        return $this->belongsToMany(Option::class, 'resrv_reservation_option')->withPivot('value')->withTrashed();
    }

    /**
     * Options for display (e.g. emails), with their values eager-loaded once including
     * soft-deleted ones. withTrashed() is required because a historical reservation can
     * reference an option value that has since been deleted; loading it here lets callers
     * resolve the selected value (pivot->value) in memory instead of querying per row.
     */
    public function optionsForEmail(): Collection
    {
        return $this->options()->with(['values' => fn ($query) => $query->withTrashed()])->get();
    }

    public function extras()
    {
        return $this->belongsToMany(Extra::class, 'resrv_reservation_extra')->withPivot(['quantity', 'price'])->withTrashed();
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
     *
     * $inTransaction runs on the locked row after the guard, before the save — for work that
     * must commit/roll back atomically (e.g. the refund gateway call). A throw aborts the transition.
     */
    public function transitionTo(ReservationStatus $to, bool $tolerant = false, ?Closure $inTransaction = null): bool
    {
        return (bool) DB::transaction(function () use ($to, $tolerant, $inTransaction) {
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

            if ($inTransaction) {
                $inTransaction($fresh);
            }

            $fresh->status = $to->value;
            $fresh->save();

            $this->lastTransitionFrom = $current;
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
        return $this->total->subtract($this->payment)->format();
    }

    public function totalToCharge()
    {
        return $this->payment->add($this->payment_surcharge)->format();
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
            $optionsCost->add($option->calculatePrice($data, $option->pivot->value));
        }

        $extrasCost = Price::create(0);
        foreach ($this->extras()->get() as $extra) {
            $extrasCost->add($extra->calculatePrice($data, $extra->pivot->quantity));
        }

        return Price::create(0)->add($optionsCost, $extrasCost);
    }

    protected function parentExtraCharges()
    {
        $totalCharges = Price::create(0);

        foreach ($this->childs as $child) {
            $data = $this->buildChildDataArray($child);

            // Fresh instances per child: OptionValue::calculatePrice mutates $this->price via
            // Price::multiply(), so reusing the same Option across children compounds the result.
            foreach ($this->options()->with('values')->get() as $option) {
                $totalCharges->add($option->calculatePrice($data, $option->pivot->value));
            }

            foreach ($this->extras()->get() as $extra) {
                // Fresh instance via withTrashed(): Extra::calculatePrice mutates price,
                // and soft-deleted extras on historical reservations must still be priced.
                $totalCharges->add(Extra::withTrashed()->find($extra->id)->calculatePrice($data, $extra->pivot->quantity));
            }
        }

        return $totalCharges;
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
        if (array_key_exists('options', $data) && $data['options']->count() > 0) {
            $data['options']->each(function ($option) use ($data, $optionsCost) {
                $optionsCost->add($this->activeOptionFor($option)->calculatePrice($data, $option['value']));
            });
        }

        $extrasCost = Price::create(0);
        if (array_key_exists('extras', $data) && $data['extras']->count() > 0) {
            // The extra class needs the entry id to calculate the price
            $data['item_id'] = $statamic_id;
            $data['extras']->each(function ($extra) use ($data, $extrasCost) {
                $extrasCost->add(Extra::find($extra['id'])->calculatePrice($data, $extra['quantity']));
            });
        }

        return $extraCharges->add($optionsCost, $extrasCost);
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
                    $totalCharges->add($this->activeOptionFor($option)->calculatePrice($childData, $option['value']));
                });
            }

            if (array_key_exists('extras', $data) && $data['extras']->count() > 0) {
                $data['extras']->each(function ($extra) use ($childData, $totalCharges) {
                    $totalCharges->add(Extra::find($extra['id'])->calculatePrice($childData, $extra['quantity']));
                });
            }
        }

        return $totalCharges;
    }

    /**
     * Resolve a checkout-submitted option to one whose value is still on offer — calculatePrice()
     * resolves withTrashed() for history, so checkout input must not book a trashed value. Also
     * rejects a missing/trashed option id (the bare Option::find() used to fatal).
     *
     * @param  array{id: int, value: int}  $option
     *
     * @throws OptionsException
     */
    protected function activeOptionFor(array $option): Option
    {
        $optionModel = Option::find($option['id']);

        if (! $optionModel || ! $optionModel->values()->whereKey($option['value'])->exists()) {
            throw new OptionsException(__('The selected option value is no longer available.'));
        }

        return $optionModel;
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
        $requiredOptionIds = Option::entry($statamic_id)
            ->where('published', true)
            ->where('required', true)
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

    /**
     * Retry a random 6-char [A-Z0-9] reference until unused (column has no unique index;
     * legacy installs may hold duplicates). Capped at 100 so a saturated table fails loudly
     * rather than spinning inside the caller's transaction; lookups also disambiguate by credential.
     */
    public function createRandomReference(): string
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $reference = Str::upper(Str::random(6));

            if (! static::query()->where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new \RuntimeException('Unable to generate a unique reservation reference after 100 attempts.');
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
