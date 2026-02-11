<?php

namespace Reach\StatamicResrv\Tests;

use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
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
        ?int $rateId = null
    ) {
        $entryData = [
            'title' => fake()->sentence(),
            'resrv_availability' => fake()->uuid(),
        ];

        $slug = Str::slug($entryData['title']);

        $collection = Collection::make($collection)->routes('/{slug}')->save();

        $this->makeBlueprint($collection);

        $entry = Entry::make()
            ->collection($collection)
            ->slug($slug)
            ->data($entryData);

        $entry->save();

        if (! $rateId) {
            $rate = Rate::factory()->create([
                'statamic_id' => $entry->id(),
                'slug' => $rateSlug ?? 'default',
                'title' => $rateSlug ? ucfirst(str_replace('-', ' ', $rateSlug)) : 'Default',
            ]);
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

    protected function createAvailabilityForEntry(Entry $entry, float $price, int $available, ?int $rateId = null, int $days = 20)
    {
        $startDate = now()->startOfDay();

        if (! $rateId) {
            $rate = Rate::where('statamic_id', $entry->id())->first();
            if (! $rate) {
                $rate = Rate::factory()->create([
                    'statamic_id' => $entry->id(),
                    'slug' => 'default',
                    'title' => 'Default',
                ]);
            }
            $rateId = $rate->id;
        }

        Availability::factory()
            ->count($days)
            ->sequence(fn ($sequence) => [
                'date' => $startDate->copy()->addDays($sequence->index),
                'price' => $price,
                'available' => $available,
                'statamic_id' => $entry->id(),
                'rate_id' => $rateId,
            ])
            ->create();
    }

    protected function createRateForEntry(Entry $entry, array $attributes = []): Rate
    {
        return Rate::factory()->create(array_merge(
            ['statamic_id' => $entry->id()],
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
                'statamic_id' => $entry->id(),
                'base_rate_id' => $baseRate->id,
            ],
            $attributes,
        ));
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
