<?php

namespace Reach\StatamicResrv\Tests;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra as ResrvExtra;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

trait CreatesEntries
{
    protected function makeStatamicItemWithAvailability(
        ?string $collection = 'pages',
        ?int $available = null,
        ?int $price = null,
        ?string $rateSlug = null,
        ?array $customAvailability = null,
        ?int $rateId = null,
        ?string $title = null
    ) {
        $entryData = [
            'title' => $title ?? fake()->sentence(),
            'resrv_availability' => fake()->uuid(),
        ];

        $slug = Str::slug($entryData['title']);

        $collection = $this->findOrCreateCollection($collection);

        $entry = Entry::make()
            ->collection($collection)
            ->slug($slug)
            ->data($entryData);

        $entry->save();

        if (! $rateId) {
            $slug = $rateSlug ?? 'default';
            $rate = Rate::where('collection', $collection->handle())
                ->where('slug', $slug)
                ->first();

            if (! $rate) {
                $rate = Rate::factory()->create([
                    'collection' => $collection->handle(),
                    'slug' => $slug,
                    'title' => $rateSlug ? ucfirst(str_replace('-', ' ', $rateSlug)) : 'Default',
                ]);
            }
            $rateId = $rate->id;
        }

        $defaultAvailabilityData = [
            'available' => $available ?? 1,
            'price' => $price ?? 50,
            'statamic_id' => $entry->id(),
            'rate_id' => $rateId,
        ];

        if ($customAvailability) {
            $dates = $customAvailability['dates'] ?? [today(), today()->addDay(), today()->addDays(2), today()->addDays(3)];
            $availables = $customAvailability['available'] ?? array_fill(0, count($dates), $defaultAvailabilityData['available']);
            $prices = $customAvailability['price'] ?? array_fill(0, count($dates), $defaultAvailabilityData['price']);

            Availability::factory()
                ->count(count($dates))
                ->sequence(fn ($sequence) => [
                    'date' => $dates[$sequence->index],
                    'available' => is_array($availables) ? $availables[$sequence->index] : $availables,
                    'price' => is_array($prices) ? $prices[$sequence->index] : $prices,
                    'statamic_id' => $defaultAvailabilityData['statamic_id'],
                    'rate_id' => $defaultAvailabilityData['rate_id'],
                ])
                ->create();
        } else {
            Availability::factory()
                ->count(4)
                ->sequence(
                    ['date' => today()],
                    ['date' => today()->addDay()],
                    ['date' => today()->addDays(2)],
                    ['date' => today()->addDays(3)],
                )
                ->create($defaultAvailabilityData);
        }

        return $entry;
    }

    public function createEntries(): SupportCollection
    {
        $entries = collect();
        $entries->put('normal', $this->makeStatamicItemWithAvailability());
        $entries->put('none-available', $this->makeStatamicItemWithAvailability(available: 0));
        $entries->put('two-available', $this->makeStatamicItemWithAvailability(available: 2));
        $entries->put('half-price', $this->makeStatamicItemWithAvailability(available: 1, price: 25));
        $stopSalesEntry = $this->makeStatamicItemWithAvailability();
        $stopSalesEntry->set('resrv_availability_field', 'disabled')->save();
        $entries->put('stop-sales', $stopSalesEntry);

        return $entries;
    }

    public function createAdvancedEntries()
    {
        return $this->createRateEntries();
    }

    public function createRateEntries()
    {
        $entries = collect();
        $entries->push($this->makeStatamicItemWithAvailability(collection: 'advanced', rateSlug: 'test'));
        $entries->push($this->makeStatamicItemWithAvailability(collection: 'advanced', available: 0, rateSlug: 'test'));
        $entries->push($this->makeStatamicItemWithAvailability(collection: 'advanced', available: 2, rateSlug: 'test'));
        $entries->push($this->makeStatamicItemWithAvailability(collection: 'advanced', available: 1, rateSlug: 'test'));
        $entries->push($this->makeStatamicItemWithAvailability(collection: 'advanced', available: 1, rateSlug: 'another-test'));
        $entries->push($this->makeStatamicItemWithAvailability(collection: 'advanced', available: 1, rateSlug: 'another-test'));
        $entries->push($this->makeStatamicItemWithAvailability(collection: 'advanced', available: 1, rateSlug: 'yet-another-test'));

        return $entries;
    }

    /**
     * Create one availability row per day for $days days starting at $from (default today), or
     * one row per value in $dates, stored as given: a date string and a Carbon are stored differently.
     */
    protected function createAvailabilityForEntry(
        Entry $entry,
        float $price,
        int $available,
        ?int $rateId = null,
        int $days = 20,
        ?CarbonInterface $from = null,
        ?array $dates = null,
    ) {
        $startDate = $from ?? now()->startOfDay();
        $dates ??= SupportCollection::times($days, fn ($day) => $startDate->copy()->addDays($day - 1))->all();

        if (! $rateId) {
            $rate = Rate::forEntry($entry->id())->first();
            if (! $rate) {
                $rate = Rate::factory()->create([
                    'collection' => $entry->collection()->handle(),
                    'slug' => 'default',
                    'title' => 'Default',
                ]);
            }
            $rateId = $rate->id;
        }

        Availability::factory()
            ->count(count($dates))
            ->sequence(fn ($sequence) => [
                'date' => $dates[$sequence->index],
                'price' => $price,
                'available' => $available,
                'statamic_id' => $entry->id(),
                'rate_id' => $rateId,
            ])
            ->create();
    }

    protected function createCheckoutEntry(): Entry
    {
        $this->findOrCreateCollection('pages');

        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        config(['resrv-config.checkout_entry' => $entry->id()]);

        return $entry;
    }

    /**
     * A cruise cabin whose stock lives on its 'double' rate (100 a night, 5 available, today to
     * today+3). The 'single' rate is priced 25% below it and books two units per addon, and a
     * 350 port-taxes extra is attached.
     *
     * @return array{0: Entry, 1: Rate, 2: Rate, 3: ResrvExtra} [$entry, $double, $single, $portTaxes]
     */
    protected function createCabinEntry(?string $title = null, array $single = [], array $portTaxes = []): array
    {
        $entry = $this->makeStatamicItemWithAvailability(
            collection: 'cabins',
            available: 5,
            price: 100,
            rateSlug: 'double',
            title: $title,
        );

        $double = Rate::forEntry($entry->id())->where('slug', 'double')->first();

        $singleRate = Rate::factory()->relative()->shared()->unitsPerAddon(2)->create([
            'collection' => 'cabins',
            'slug' => 'single',
            'title' => 'Single occupancy',
            'base_rate_id' => $double->id,
            'modifier_type' => 'percent',
            'modifier_operation' => 'decrease',
            'modifier_amount' => 25,
            ...$single,
        ]);

        $portTaxesExtra = ResrvExtra::factory()->fixed()->create([
            'name' => 'Port taxes',
            'slug' => 'port-taxes',
            'price' => '350',
            ...$portTaxes,
        ]);

        ResrvEntry::whereItemId($entry->id())->extras()->attach($portTaxesExtra->id);

        return [$entry, $double, $singleRate, $portTaxesExtra];
    }

    protected function awaitingPaymentReservation(array $attributes = []): Reservation
    {
        $item = $this->makeStatamicItem();

        return Reservation::factory()->withCustomer()->create(array_merge([
            'item_id' => $item->id(),
            'status' => ReservationStatus::AWAITING_PAYMENT->value,
        ], $attributes));
    }

    protected function createRateForEntry(Entry $entry, array $attributes = []): Rate
    {
        return Rate::factory()->create(array_merge(
            ['collection' => $entry->collection()->handle()],
            $attributes,
        ));
    }

    protected function createRelativeRate(Entry $entry, Rate $baseRate, array $attributes = []): Rate
    {
        return $this->createDependentRate('relative', $entry, $baseRate, $attributes);
    }

    protected function createSharedRate(Entry $entry, Rate $baseRate, array $attributes = []): Rate
    {
        return $this->createDependentRate('shared', $entry, $baseRate, $attributes);
    }

    private function createDependentRate(string $type, Entry $entry, Rate $baseRate, array $attributes = []): Rate
    {
        return Rate::factory()->{$type}()->create(array_merge(
            [
                'collection' => $entry->collection()->handle(),
                'base_rate_id' => $baseRate->id,
            ],
            $attributes,
        ));
    }

    protected function findOrCreateCollection(string $handle): \Statamic\Contracts\Entries\Collection
    {
        if ($existing = Collection::findByHandle($handle)) {
            return $existing;
        }

        $collection = Collection::make($handle)->routes('/{slug}');
        $collection->save();

        $this->makeBlueprint($collection);

        return $collection;
    }

    protected function makeBlueprint($collection)
    {
        $fields = [
            [
                'handle' => 'title',
                'field' => [
                    'type' => 'text',
                    'display' => 'Title',
                ],
            ],
            [
                'handle' => 'slug',
                'field' => [
                    'type' => 'text',
                    'display' => 'Slug',
                ],
            ],
            [
                'handle' => 'resrv_availability_field',
                'field' => [
                    'type' => 'resrv_availability',
                    'display' => 'Resrv Availability',
                ],
            ],
        ];

        $blueprint = Blueprint::make()->setContents([
            'sections' => [
                'main' => [
                    'fields' => $fields,
                ],
            ],
        ]);
        $blueprint->setHandle($collection->handle())->setNamespace('collections.'.$collection->handle())->save();
    }
}
