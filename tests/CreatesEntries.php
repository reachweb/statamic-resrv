<?php

namespace Reach\StatamicResrv\Tests;

use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Models\Availability;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection;

trait CreatesEntries
{
    protected function makeStatamicItemWithAvailability(?int $available = null, ?int $price = null, ?string $advanced = null)
    {
        $entryData = [
            'title' => fake()->sentence(),
            'resrv_availability' => fake()->uuid(),
        ];

        $slug = Str::slug($entryData['title']);

        Collection::make('pages')->routes('/{slug}')->save();

        $entry = Entry::make()
            ->collection('pages')
            ->slug($slug)
            ->data($entryData);

        $entry->save();

        $availabilityData = [
            'available' => $available ?? 1,
            'price' => $price ?? 50,
            'statamic_id' => $entry->id(),
            'property' => $property ?? 'none',
        ];

        Availability::factory()
            ->count(4)
            ->sequence(
                ['date' => today()],
                ['date' => today()->add(1, 'day')],
                ['date' => today()->add(2, 'day')],
                ['date' => today()->add(3, 'day')],
            )
            ->create($availabilityData);

        return $entry;
    }

    public function createEntries(): SupportCollection
    {
        $entries = collect();
        $entries->push($this->makeStatamicItemWithAvailability());
        $entries->push($this->makeStatamicItemWithAvailability(available: 0));
        $entries->push($this->makeStatamicItemWithAvailability(available: 2));
        $entries->push($this->makeStatamicItemWithAvailability(available: 1, price: 35));

        return $entries;
    }

    public function createAdvancedEntries()
    {
        $entries = collect();
        $entries->push($this->makeStatamicItemWithAvailability(advanced: 'test'));
        $entries->push($this->makeStatamicItemWithAvailability(available: 0, advanced: 'test'));
        $entries->push($this->makeStatamicItemWithAvailability(available: 2, advanced: 'test'));
        $entries->push($this->makeStatamicItemWithAvailability(available: 1, price: 35, advanced: 'test'));

        return $entries;
    }
}
