<?php

namespace Reach\StatamicResrv\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Reach\StatamicResrv\Models\Entry;
use Reach\StatamicResrv\Models\Option;

class OptionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Option::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => 'Reservation option',
            'slug' => 'reservation-option',
            'description' => 'Select this option to improve your experience!',
            'required' => true,
            'order' => 1,
            'collection' => null,
            'apply_to_all' => false,
            'published' => true,
        ];
    }

    public function notRequired()
    {
        return $this->state(function (array $attributes) {
            return [
                'required' => false,
            ];
        });
    }

    /**
     * Attach the option to a single entry: derive the entry's collection and create the pivot row,
     * the global-options equivalent of the old item_id binding.
     */
    public function forEntry(string $entryId): static
    {
        return $this->afterCreating(function (Option $option) use ($entryId) {
            $collection = Entry::collectionForItem($entryId);

            $option->forceFill(['collection' => $collection])->saveQuietly();
            $option->entries()->syncWithoutDetaching([$entryId]);
        });
    }
}
