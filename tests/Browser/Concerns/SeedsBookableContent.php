<?php

namespace Reach\StatamicResrv\Tests\Browser\Concerns;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
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
        $this->wireCheckoutEntries();

        return $entry;
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
            'resrv_availability' => (string) Str::uuid(),
        ]);
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
        $checkout = $this->ensurePageEntry($this->checkoutSlug, 'Checkout');
        $completed = $this->ensurePageEntry($this->checkoutCompletedSlug, 'Checkout Completed');

        Config::set('resrv-config.checkout_entry', $checkout->id());
        Config::set('resrv-config.checkout_completed_entry', $completed->id());
    }
}
