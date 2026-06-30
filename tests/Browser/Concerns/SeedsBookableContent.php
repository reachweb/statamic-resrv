<?php

namespace Reach\StatamicResrv\Tests\Browser\Concerns;

use Illuminate\Support\Facades\Config;
use Reach\StatamicResrv\Models\Availability;
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

    protected string $checkoutSlug = 'checkout';

    protected string $checkoutCompletedSlug = 'checkout-completed';

    protected int $availabilityDays = 20;

    protected function seedBookableContent(): EntryContract
    {
        $this->ensureBookableCollection();

        $entry = $this->ensureBookableEntry();
        $rate = $this->ensureRate();

        $this->seedAvailabilityWindow($entry, $rate);
        $this->attachExtra($entry);
        $this->attachOption($entry);

        $this->ensureMultiEntry($rate);
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
        if (Form::find($this->checkoutSlug)) {
            return;
        }

        $blueprintContents = YAML::file(
            dirname(__DIR__, 3).'/resources/blueprints/forms/checkout.yaml'
        )->parse();

        Blueprint::make($this->checkoutSlug)
            ->setNamespace('forms')
            ->setContents($blueprintContents)
            ->save();

        Form::make($this->checkoutSlug)->title('Checkout')->save();
    }

    /**
     * Create the `pages` collection (route `/{slug}`) and a blueprint carrying
     * the resrv_availability fieldtype, mirroring
     * tests/CreatesEntries::makeBlueprint().
     */
    protected function ensureBookableCollection(): void
    {
        if (Collection::findByHandle($this->bookableCollection)) {
            return;
        }

        Collection::make($this->bookableCollection)->routes('/{slug}')->save();

        Blueprint::make()
            ->setHandle($this->bookableCollection)
            ->setNamespace('collections.'.$this->bookableCollection)
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
     * The cart-flow entry (T15). Seeded like the bookable entry — one rate is
     * enough to render `availability-multi-results`; T15 adds the second rate.
     */
    protected function ensureMultiEntry(Rate $rate): EntryContract
    {
        $entry = $this->ensurePageEntry($this->multiSlug, 'Multi-rate Room', [
            'resrv_availability' => fake()->uuid(),
            'template' => 'multi',
        ]);

        $this->seedAvailabilityWindow($entry, $rate);
        $this->attachExtra($entry);
        $this->attachOption($entry);

        return $entry;
    }

    /**
     * Find-or-create a `pages` entry by slug and (re)save it so the synchronous
     * EntrySaved listener guarantees the matching resrv_entries row exists.
     *
     * @param  array<string, mixed>  $data
     */
    protected function ensurePageEntry(string $slug, string $title, array $data = []): EntryContract
    {
        $entry = Entry::query()
            ->where('collection', $this->bookableCollection)
            ->where('slug', $slug)
            ->first();

        if (! $entry) {
            $entry = Entry::make()
                ->collection($this->bookableCollection)
                ->slug($slug);
        }

        $entry->data(array_merge(['title' => $title], $data))->save();

        return $entry;
    }

    protected function ensureRate(): Rate
    {
        return Rate::where('collection', $this->bookableCollection)
            ->where('slug', $this->rateSlug)
            ->first()
            ?? Rate::factory()->create([
                'collection' => $this->bookableCollection,
                'slug' => $this->rateSlug,
                'title' => 'Default',
                'apply_to_all' => true,
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
