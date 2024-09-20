<?php

namespace Reach\StatamicResrv\Tests;

use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Models\Availability;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection;

trait CreatesEntries
{
    protected function makeStatamicItemWithAvailability(
        ?string $collection = 'pages',
        ?int $available = null,
        ?int $price = null,
        ?string $advanced = null,
        ?array $customAvailability = null
    ) {
        $entryData = [
            'title' => fake()->sentence(),
            'resrv_availability' => fake()->uuid(),
        ];

        $slug = Str::slug($entryData['title']);

        Collection::make($collection)->routes('/{slug}')->save();

        $entry = Entry::make()
            ->collection($collection)
            ->slug($slug)
            ->data($entryData);

        $entry->save();

        $defaultAvailabilityData = [
            'available' => $available ?? 1,
            'price' => $price ?? 50,
            'statamic_id' => $entry->id(),
            'property' => $advanced ?? 'none',
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
                    'property' => $defaultAvailabilityData['property'],
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
        $stopSalesEntry->set('resrv_availability', 'disabled')->save();
        $entries->put('stop-sales', $stopSalesEntry);

        return $entries;
    }

    public function createAdvancedEntries()
    {
        $entries = collect();
        $entries->push($this->makeStatamicItemWithAvailability(collection: 'advanced', advanced: 'test'));
        $entries->push($this->makeStatamicItemWithAvailability(collection: 'advanced', available: 0, advanced: 'test'));
        $entries->push($this->makeStatamicItemWithAvailability(collection: 'advanced', available: 2, advanced: 'test'));
        $entries->push($this->makeStatamicItemWithAvailability(collection: 'advanced', available: 1, advanced: 'test'));
        $entries->push($this->makeStatamicItemWithAvailability(collection: 'advanced', available: 1, advanced: 'another-test'));
        $entries->push($this->makeStatamicItemWithAvailability(collection: 'advanced', available: 1, advanced: 'another-test'));
        $entries->push($this->makeStatamicItemWithAvailability(collection: 'advanced', available: 1, advanced: 'yet-another-test'));

        return $entries;
    }
}
