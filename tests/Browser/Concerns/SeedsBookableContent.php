<?php

namespace Reach\StatamicResrv\Tests\Browser\Concerns;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Rate;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\YAML;

/**
 * Shared bookable-content seeding for the browser-test harness.
 *
 * One implementation, two consumers: the workbench DatabaseSeeder (run by
 * `workbench:build` so a `testbench serve` has content to render) and the Dusk
 * BrowserTestCase fixtures (T10+). It ports the logic of
 * tests/CreatesEntries::makeStatamicItemWithAvailability() and
 * tests/Livewire/CheckoutTest::setUp(), but the availability window is
 * deliberately wide and starts *tomorrow* — never today() — to dodge
 * midnight/timezone flakes and to give range/extra-days scenarios room.
 *
 * Every step is find-or-create so the trait is safe to call repeatedly (e.g. a
 * truncate-then-reseed test lifecycle): Statamic entries persist to disk and
 * survive a DB truncation, while the DB-backed models are recreated.
 */
trait SeedsBookableContent
{
    protected string $bookableCollection = 'pages';

    protected string $bookableSlug = 'bookable';

    protected string $multiSlug = 'multi';

    protected string $rateSlug = 'default';

    protected string $multiSecondRateSlug = 'children';

    protected string $roomsCollection = 'rooms';

    protected string $roomTwoRateSlug = 'room-flex';

    protected string $roomOneRateSlug = 'room-solo';

    protected string $checkoutSlug = 'checkout';

    protected string $checkoutCompletedSlug = 'checkout-completed';

    protected int $availabilityDays = 20;

    protected function seedBookableContent(): EntryContract
    {
        $this->ensureAvailabilityCollection($this->bookableCollection, '/{slug}');

        $entry = $this->ensureBookableEntry();
        $rate = $this->ensureRate();

        $this->seedAvailabilityWindow($entry, $rate);
        $this->attachExtra($entry);
        $this->attachOption($entry);
        $this->attachCoupons($entry);

        $this->ensureMultiEntry($rate);
        $this->ensureRoomsContent();
        $this->ensureCheckoutForm();
        $this->wireCheckoutEntries();

        return $entry;
    }

    /**
     * Provision the Statamic `checkout` form the served app needs at checkout step
     * 2. CheckoutFormResolver does `Form::find('checkout')`; a real install ships
     * the form + its field blueprint via the addon's `resrv-forms`/`resrv-blueprints`
     * publish tags, but the workbench build never publishes them, so the served app
     * (a separate process from the seeding test) would throw CheckoutFormNotFoundException
     * — the checkout-form render 500s and step 2 silently never appears. Recreate the
     * form and its fields blueprint from the addon's own shipped YAML so the resolver
     * resolves the same first_name/last_name/email/repeat_email fields as production.
     * Find-or-create keeps it truncate-then-reseed safe; both files land under the
     * shared skeleton resource_path the served app reads.
     */
    protected function ensureCheckoutForm(): void
    {
        $blueprintContents = YAML::file(
            dirname(__DIR__, 3).'/resources/blueprints/forms/checkout.yaml'
        )->parse();

        // T16 drives the two field types with a real JS surface that the shipped form
        // lacks: a dictionary_phone combobox (a `dictionary` field whose config is the
        // exact string `country_phone_codes` — that is what CheckoutForm::isPhoneDictionary()
        // matches to pick the phonebox over the plain dictionary), and a toggle switch.
        // Both are optional, so the standard checkout funnel (T14) still submits with them
        // left untouched. The blueprint is always (re)written — Statamic forms persist on
        // disk and survive DB truncation, so an early return would keep a stale form from an
        // earlier run that lacks these fields.
        $blueprintContents['tabs']['main']['sections'][0]['fields'][] = [
            'handle' => 'dialing_code',
            'field' => [
                'display' => 'Dialing code',
                'type' => 'dictionary',
                'dictionary' => 'country_phone_codes',
                'width' => 50,
                'localizable' => false,
            ],
        ];

        $blueprintContents['tabs']['main']['sections'][0]['fields'][] = [
            'handle' => 'newsletter',
            'field' => [
                'display' => 'Subscribe to the newsletter',
                'type' => 'toggle',
                'width' => 100,
                'localizable' => false,
            ],
        ];

        Blueprint::make($this->checkoutSlug)
            ->setNamespace('forms')
            ->setContents($blueprintContents)
            ->save();

        if (! Form::find($this->checkoutSlug)) {
            Form::make($this->checkoutSlug)->title('Checkout')->save();
        }
    }

    /**
     * Find-or-create a collection with the given route and a blueprint carrying the
     * resrv_availability fieldtype, mirroring tests/CreatesEntries::makeBlueprint().
     * Shared by the `pages` (route `/{slug}`) and `rooms` (route `/rooms/{slug}`)
     * collections.
     *
     * The collection is created conditionally, but the blueprint is ALWAYS (re)written —
     * the two live in different persistence domains: the collection persists in the
     * gitignored workbench/content, while its blueprint lives under the Testbench skeleton's
     * resource_path (inside vendor/). A dependency reinstall wipes the blueprint while the
     * collection survives, so guarding the blueprint on the collection's existence would leave
     * entries with no resrv_availability field. Entry::syncToDatabase() then skips them, no
     * resrv_entries rows are written, and a later workbench:build fails with "No query results
     * for model [Reach\StatamicResrv\Models\Entry]". Mirrors ensureCheckoutForm()'s always-write.
     */
    protected function ensureAvailabilityCollection(string $handle, string $route): void
    {
        if (! Collection::findByHandle($handle)) {
            Collection::make($handle)->routes($route)->save();
        }

        Blueprint::make()
            ->setHandle($handle)
            ->setNamespace('collections.'.$handle)
            ->setContents([
                'sections' => [
                    'main' => [
                        'fields' => [
                            ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
                            ['handle' => 'slug', 'field' => ['type' => 'text', 'display' => 'Slug']],
                            ['handle' => 'resrv_availability_field', 'field' => ['type' => 'resrv_availability', 'display' => 'Resrv Availability']],
                        ],
                    ],
                ],
            ])
            ->save();
    }

    protected function ensureBookableEntry(): EntryContract
    {
        return $this->ensurePageEntry($this->bookableSlug, 'Bookable Room', [
            'resrv_availability' => fake()->uuid(),
            'template' => 'bookable',
        ]);
    }

    /**
     * The cart-flow entry (T15). Carries TWO rates so the cart has more than one
     * line to combine: the shared apply_to_all `default` rate, plus a second rate
     * scoped to *only* this entry. Scoping the second rate via the pivot (with
     * apply_to_all = false) keeps the single-rate bookable entry that T13/T14 rely
     * on untouched. Each rate gets its own availability window (independent rates
     * don't share inventory).
     */
    protected function ensureMultiEntry(Rate $rate): EntryContract
    {
        $entry = $this->ensurePageEntry($this->multiSlug, 'Multi-rate Room', [
            'resrv_availability' => fake()->uuid(),
            'template' => 'multi',
        ]);

        $secondRate = $this->ensureMultiSecondRate($entry);

        $this->seedAvailabilityWindow($entry, $rate);
        $this->seedAvailabilityWindow($entry, $secondRate);
        $this->attachExtra($entry);
        $this->attachOption($entry);

        return $entry;
    }

    /**
     * A second rate that applies to ONLY the multi entry: apply_to_all = false and
     * attached through the resrv_rate_entries pivot (find-or-create, so truncate-
     * then-reseed safe). Independent pricing/availability with no max cap, mirroring
     * tests/Livewire/AvailabilityMultiResultsTest::createMultiRateEntry()'s children
     * rate, so the cart resolves it at availability=1, quantity=1.
     */
    protected function ensureMultiSecondRate(EntryContract $entry): Rate
    {
        $rate = $this->firstOrCreateRate($this->bookableCollection, $this->multiSecondRateSlug, 'Children', false);

        $rate->entries()->syncWithoutDetaching([$entry->id()]);

        return $rate;
    }

    /**
     * A SECOND collection ('rooms') for T20's cross-collection rate reconciliation: an
     * entry with two rates and one with a single rate, whose rate ids are mutually
     * foreign to collection A ('pages'). Entry-scoped rates (apply_to_all = false + pivot)
     * so `Rate::forEntry()` returns exactly two for the flex room and one for the solo room.
     */
    protected function ensureRoomsContent(): void
    {
        $this->ensureAvailabilityCollection($this->roomsCollection, '/rooms/{slug}');

        $flexRoom = $this->ensureRoomEntry($this->roomTwoRateSlug, 'Flex Room');
        $soloRoom = $this->ensureRoomEntry($this->roomOneRateSlug, 'Solo Room');

        $this->ensureRoomRate('rooms-flex', 'Flex', $flexRoom);
        $this->ensureRoomRate('rooms-standard', 'Standard', $flexRoom);
        $this->ensureRoomRate('rooms-solo', 'Solo', $soloRoom);
    }

    protected function ensureRoomEntry(string $slug, string $title): EntryContract
    {
        $entry = $this->firstOrMakeEntry($this->roomsCollection, $slug);

        // template => 'room' gives the entry a detail page that renders (workbench/resources/
        // views/room.blade.php) so AvailabilityCollection::select()'s redirect target
        // ($entry->url() → /rooms/{slug}) is a clean 200 rather than a missing-template 500 —
        // T19 drives that select → detail-page redirect.
        $entry->data(['title' => $title, 'resrv_availability' => fake()->uuid(), 'template' => 'room'])->save();

        return $entry;
    }

    protected function ensureRoomRate(string $slug, string $title, EntryContract $entry): void
    {
        $rate = $this->firstOrCreateRate($this->roomsCollection, $slug, $title, false);

        $rate->entries()->syncWithoutDetaching([$entry->id()]);
        $this->seedAvailabilityWindow($entry, $rate);
    }

    /**
     * Find-or-create a `pages` entry by slug and (re)save it so the synchronous
     * EntrySaved listener guarantees the matching resrv_entries row exists.
     *
     * @param  array<string, mixed>  $data
     */
    protected function ensurePageEntry(string $slug, string $title, array $data = []): EntryContract
    {
        $entry = $this->firstOrMakeEntry($this->bookableCollection, $slug);

        $entry->data(array_merge(['title' => $title], $data))->save();

        return $entry;
    }

    /**
     * Find an entry by (collection, slug) or make a fresh unsaved one — the shared
     * find-or-make skeleton behind the page and room entry seeders.
     */
    protected function firstOrMakeEntry(string $collection, string $slug): EntryContract
    {
        return Entry::query()->where('collection', $collection)->where('slug', $slug)->first()
            ?? Entry::make()->collection($collection)->slug($slug);
    }

    protected function ensureRate(): Rate
    {
        return $this->firstOrCreateRate($this->bookableCollection, $this->rateSlug, 'Default', true);
    }

    /**
     * Find-or-create a Rate for the given collection/slug pair (truncate-then-reseed safe).
     */
    protected function firstOrCreateRate(string $collection, string $slug, string $title, bool $applyToAll): Rate
    {
        return Rate::where('collection', $collection)->where('slug', $slug)->first()
            ?? Rate::factory()->create([
                'collection' => $collection,
                'slug' => $slug,
                'title' => $title,
                'apply_to_all' => $applyToAll,
            ]);
    }

    protected function seedAvailabilityWindow(EntryContract $entry, Rate $rate): void
    {
        if (Availability::where('statamic_id', $entry->id())->where('rate_id', $rate->id)->exists()) {
            return;
        }

        Availability::factory()
            ->count($this->availabilityDays)
            ->sequence(fn ($sequence) => [
                'date' => today()->addDays($sequence->index + 1),
                'available' => 1,
                'price' => 50,
                'statamic_id' => $entry->id(),
                'rate_id' => $rate->id,
            ])
            ->create();
    }

    protected function attachExtra(EntryContract $entry): void
    {
        $extra = Extra::first() ?? Extra::factory()->create();

        ResrvEntry::whereItemId($entry->id())->extras()->syncWithoutDetaching([$extra->id]);
    }

    protected function attachOption(EntryContract $entry): void
    {
        if (Option::where('item_id', $entry->id())->exists()) {
            return;
        }

        Option::factory()
            ->notRequired()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->create(['item_id' => $entry->id()]);
    }

    /**
     * Attach two coupons (a percentage and a flat discount) to the bookable entry for
     * T18. Coupons are DynamicPricing rows carrying a `coupon` code; they only discount
     * when their code is the active session coupon, so their presence never changes the
     * base totals the other funnel tasks (T14/T16/T17) assert. `noDates()` skips the
     * date-window check, and duration >= 1 matches the one-night funnel booking.
     */
    protected function attachCoupons(EntryContract $entry): void
    {
        $this->ensureCoupon($entry, 'SAVE20', 'percent', '20');
        $this->ensureCoupon($entry, 'SAVE5', 'fixed', '5');
    }

    protected function ensureCoupon(EntryContract $entry, string $code, string $amountType, string $amount): void
    {
        $coupon = DynamicPricing::where('coupon', $code)->first()
            ?? DynamicPricing::factory()->noDates()->create([
                'title' => 'Browser coupon '.$code,
                'coupon' => $code,
                'amount_type' => $amountType,
                'amount_operation' => 'decrease',
                'amount' => $amount,
                'condition_type' => 'reservation_duration',
                'condition_comparison' => '>=',
                'condition_value' => '1',
            ]);

        // The entries() morph maps the pivot's assignment_id to Availability.statamic_id,
        // so store the entry's statamic id (mirrors tests/Livewire/CheckoutTest). Idempotent
        // for truncate-then-reseed.
        DB::table('resrv_dynamic_pricing_assignments')->updateOrInsert([
            'dynamic_pricing_id' => $coupon->id,
            'dynamic_pricing_assignment_id' => $entry->id(),
            'dynamic_pricing_assignment_type' => Availability::class,
        ]);
    }

    /**
     * Create the checkout + checkout-completed entries and point the
     * resrv-config keys at them (Gotcha #9). The served app re-resolves the same
     * entries by slug in WorkbenchServiceProvider so both processes agree.
     */
    protected function wireCheckoutEntries(): void
    {
        $checkout = $this->ensurePageEntry($this->checkoutSlug, 'Checkout', ['template' => 'checkout']);
        $completed = $this->ensurePageEntry($this->checkoutCompletedSlug, 'Checkout Completed', ['template' => 'checkout-completed']);

        Config::set('resrv-config.checkout_entry', $checkout->id());
        Config::set('resrv-config.checkout_completed_entry', $completed->id());
    }

    /**
     * One shared source of stable browser selectors so Phase-4 tests don't depend
     * on translated text or Tailwind classes. Most controls reuse hooks already in
     * the markup (`name`, `aria-label`, `wire:click`/`wire:model`); the few with no
     * stable hook carry a `dusk="…"` attribute added to the package blade in T8
     * (quantity stepper, coupon input, gateway buttons, offline confirm). Values
     * are Dusk-ready: `@name` resolves to `[dusk="name"]`, the rest are CSS.
     *
     * @return array<string, string>
     */
    protected function browserSelectors(): array
    {
        return [
            // AvailabilitySearch — calendar + controls
            'dateInput' => '[name=datepicker]',
            'clearDates' => '[aria-label="Clear selection"]',
            'rateSelect' => '[wire\:model\.live="data.rate"]',
            'quantityDecrease' => '@quantity-decrease',
            'quantityIncrease' => '@quantity-increase',
            'quantityInput' => '@quantity-input',
            'searchSubmit' => '[wire\:click="submit()"]',

            // Checkout flow — actions, coupon, gateways, confirm
            'proceed' => '[wire\:click="handleFirstStep()"]',
            'couponInput' => '@coupon-input',
            'couponApply' => '[wire\:click\.debounce="addCoupon(coupon)"]',
            'couponRemove' => '[wire\:click\.debounce="removeCoupon()"]',
            'confirmPayment' => '@confirm-payment',

            // Gateway buttons are keyed by name: append the gateway, e.g.
            // $this->browserSelectors()['gatewayPrefix'].'offline' → '@gateway-offline'.
            'gatewayPrefix' => '@gateway-',
        ];
    }
}
