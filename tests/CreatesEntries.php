<?php

namespace Reach\StatamicResrv\Tests;

trait CreatesEntries
{
    public $entries = [];

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
            ->data($entryData)
            ->save();

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

    public function createEntries()
    {
        $this->entries[] = $this->makeStatamicItemWithAvailability();
        $this->entries[] = $this->makeStatamicItemWithAvailability(available: 0);
        $this->entries[] = $this->makeStatamicItemWithAvailability(available: 2);
        $this->entries[] = $this->makeStatamicItemWithAvailability(available: 1, price: 35);

        return $this->entries;
    }

    public function createAdvancedEntries()
    {
        $this->entries[] = $this->makeStatamicItemWithAvailability(advanced: 'test');
        $this->entries[] = $this->makeStatamicItemWithAvailability(available: 0, advanced: 'test');
        $this->entries[] = $this->makeStatamicItemWithAvailability(available: 2, advanced: 'test');
        $this->entries[] = $this->makeStatamicItemWithAvailability(available: 1, price: 35, advanced: 'test');

        return $this->entries;
    }
}