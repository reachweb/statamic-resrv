<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityCart;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;

class AvailabilityCartTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public $advancedEntries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
        $this->advancedEntries = $this->createAdvancedEntries();
        $this->travelTo(today()->setHour(12));
    }

    // Test that the component renders successfully
    public function test_renders_successfully()
    {
        Livewire::test(AvailabilityCart::class)
            ->assertViewIs('statamic-resrv::livewire.availability-cart')
            ->assertStatus(200);
    }

    // Test that it shows empty cart message when cart is empty
    public function test_shows_empty_cart_message()
    {
        Livewire::test(AvailabilityCart::class)
            ->assertSee(trans('statamic-resrv::frontend.reservationsEmpty'))
            ->assertDontSee(trans('statamic-resrv::frontend.total'));
    }

    // Test that it can add an item to the cart
    public function test_can_add_item_to_cart()
    {
        $availabilityData = [
            'dates' => [
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
            ],
            'quantity' => 1,
            'advanced' => null,
        ];

        $results = app(Availability::class)->getAvailabilityForEntry($this->availabilityArray($availabilityData), $this->entries->first()->id());

        $component = Livewire::test(AvailabilityCart::class)
            ->dispatch('add-to-cart',
                entryId: $this->entries->first()->id(),
                availabilityData: $availabilityData,
                results: $results
            );

        $component
            ->assertViewHas('itemCount', 1)
            ->assertViewHas('allValid', true)
            ->assertSee($this->calculateTotalPrice($results))
            ->assertSee(trans('statamic-resrv::frontend.bookNow'));
    }

    // Test that it can remove an item from the cart
    public function test_can_remove_item_from_cart()
    {
        $availabilityData = [
            'dates' => [
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
            ],
            'quantity' => 1,
            'advanced' => null,
        ];

        $results = app(Availability::class)->getAvailabilityForEntry($this->availabilityArray($availabilityData), $this->entries->first()->id());

        // Add item to cart
        $component = Livewire::test(AvailabilityCart::class)
            ->dispatch('add-to-cart',
                entryId: $this->entries->first()->id(),
                availabilityData: $availabilityData,
                results: $results
            );

        // Get the item ID that was generated
        $itemId = $component->get('cart.items')->keys()->first();

        // Remove the item
        $component
            ->dispatch('remove-from-cart', itemId: $itemId)
            ->assertViewHas('itemCount', 0)
            ->assertSee(trans('statamic-resrv::frontend.reservationsEmpty'));
    }

    // Test that it detects duplicate items and updates instead of adding
    public function test_detects_duplicate_entries_in_cart()
    {
        $availabilityData = [
            'dates' => [
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
            ],
            'quantity' => 1,
            'advanced' => null,
        ];

        $results = app(Availability::class)->getAvailabilityForEntry($this->availabilityArray($availabilityData), $this->entries->first()->id());

        // Add first item to cart
        $component = Livewire::test(AvailabilityCart::class)
            ->dispatch('add-to-cart',
                entryId: $this->entries->first()->id(),
                availabilityData: $availabilityData,
                results: $results
            );

        // Verify first item was added correctly
        $this->assertEquals(1, $component->get('cart.items')->count());
        $firstItemId = $component->get('cart.items')->keys()->first();
        $firstItem = $component->get('cart.items')->first();

        $this->assertEquals($this->entries->first()->id(), $firstItem->entryId);
        $this->assertEquals($availabilityData, $firstItem->availabilityData);
        $this->assertEquals($results, $firstItem->results);

        // Add a second item
        $differentDatesData = [
            'dates' => [
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add(3, 'day')->toISOString(),
            ],
            'quantity' => 1,
            'advanced' => null,
        ];

        $differentResults = app(Availability::class)->getAvailabilityForEntry(
            $this->availabilityArray($differentDatesData),
            $this->entries->first()->id()
        );

        $component = Livewire::test(AvailabilityCart::class)
            ->dispatch('add-to-cart',
                entryId: $this->entries->first()->id(),
                availabilityData: $differentDatesData,
                results: $differentResults
            );

        // Verify second item was added
        $this->assertEquals(2, $component->get('cart.items')->count());
        $secondItemDates = $component->get('cart.items')->contains(function ($item) use ($differentDatesData) {
            return $item->availabilityData === $differentDatesData;
        });
        $this->assertTrue($secondItemDates);

        $secondItemId = $component->get('cart.items')->keys()->last();

        // Change quantity in availability data
        $updatedAvailabilityData = $differentDatesData;
        $updatedAvailabilityData['quantity'] = 2;
        $updatedResults = app(Availability::class)->getAvailabilityForEntry(
            $this->availabilityArray($updatedAvailabilityData),
            $this->entries->first()->id()
        );

        // Add with updated quantity (should update, not add)
        $component->dispatch('add-to-cart',
            entryId: $this->entries->first()->id(),
            availabilityData: $updatedAvailabilityData,
            results: $updatedResults
        );

        // Still should have only 2 items
        $this->assertEquals(2, $component->get('cart.items')->count());

        // Verify we have an item with both dates
        $hasItemWithOriginalDates = $component->get('cart.items')->contains(function ($item) use ($availabilityData) {
            return $item->availabilityData === $availabilityData;
        });
        $this->assertTrue($hasItemWithOriginalDates);

        $hasItemWithDifferentDates = $component->get('cart.items')->contains(function ($item) use ($updatedAvailabilityData) {
            return $item->availabilityData === $updatedAvailabilityData;
        });
        $this->assertTrue($hasItemWithDifferentDates);

        // But the quantity should be updated
        $updatedItem = $component->get('cart.items')->get($secondItemId);
        $this->assertEquals(2, $updatedItem->availabilityData['quantity']);

        // // And results should match the updated ones
        $this->assertEquals($updatedResults, $updatedItem->results);

        // Verify the original items still exist
        $this->assertTrue($component->get('cart.items')->has($firstItemId));
        $this->assertTrue($component->get('cart.items')->has($secondItemId));

    }

    // Test that it calculates prices correctly for multiple items
    public function test_calculates_prices_correctly_for_multiple_items()
    {
        $availabilityData1 = [
            'dates' => [
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
            ],
            'quantity' => 1,
            'advanced' => null,
        ];

        $availabilityData2 = [
            'dates' => [
                'date_start' => $this->date->copy()->addDays(5)->toISOString(),
                'date_end' => $this->date->copy()->addDays(7)->toISOString(),
            ],
            'quantity' => 2,
            'advanced' => null,
        ];

        $results1 = app(Availability::class)->getAvailabilityForEntry($this->availabilityArray($availabilityData1), $this->entries->first()->id());

        // Create availability for second test
        Availability::factory()
            ->count(4)
            ->sequence(
                ['date' => $this->date->copy()->addDays(5)->startOfDay()],
                ['date' => $this->date->copy()->addDays(6)->startOfDay()],
                ['date' => $this->date->copy()->addDays(7)->startOfDay()],
                ['date' => $this->date->copy()->addDays(8)->startOfDay()],
            )
            ->create([
                'statamic_id' => $this->entries->first()->id(),
                'price' => 50,
                'available' => 2,
            ]);

        $results2 = app(Availability::class)->getAvailabilityForEntry($this->availabilityArray($availabilityData2), $this->entries->first()->id());

        // Calculate total price manually
        $expectedTotal =
            (float) str_replace(',', '', $results1['data']['price']) +
            (float) str_replace(',', '', $results2['data']['price']);
        $expectedTotal = number_format($expectedTotal, 2, '.', '');

        // Add items to cart
        $component = Livewire::test(AvailabilityCart::class)
            ->dispatch('add-to-cart',
                entryId: $this->entries->first()->id(),
                availabilityData: $availabilityData1,
                results: $results1
            )
            ->dispatch('add-to-cart',
                entryId: $this->entries->first()->id(),
                availabilityData: $availabilityData2,
                results: $results2
            )
            ->assertViewHas('itemCount', 2);

        // Check for correct total price
        $component
            ->assertSee($expectedTotal);
    }

    // // Test that it creates parent reservation with child reservations on checkout
    // public function test_creates_parent_and_child_reservations_on_checkout()
    // {
    //     $entry = Entry::make()
    //         ->collection('pages')
    //         ->slug('checkout')
    //         ->data(['title' => 'Checkout']);

    //     $entry->save();

    //     Config::set('resrv-config.checkout_entry', $entry->id());

    //     $availabilityData = [
    //         'dates' => [
    //             'date_start' => $this->date->toISOString(),
    //             'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
    //         ],
    //         'quantity' => 1,
    //         'advanced' => null,
    //     ];

    //     $results = app(Availability::class)->getAvailabilityForEntry($availabilityData, $this->entries->first()->id());

    //     // Add item to cart and checkout
    //     $component = Livewire::test(AvailabilityCart::class)
    //         ->dispatch('add-to-cart',
    //             entryId: $this->entries->first()->id(),
    //             availabilityData: $availabilityData,
    //             results: $results
    //         )
    //         ->call('checkout')
    //         ->assertSessionHas('resrv_reservation', 1)
    //         ->assertRedirect($entry->url());

    //     // Check that a parent reservation was created
    //     $this->assertDatabaseHas('resrv_reservations', [
    //         'id' => 1,
    //         'type' => 'parent',
    //         'status' => 'pending',
    //         'price' => $results['data']['price'],
    //     ]);

    //     // Check that a child reservation was created and linked to the parent
    //     $this->assertDatabaseHas('resrv_reservations', [
    //         'id' => 2,
    //         'item_id' => $this->entries->first()->id(),
    //         'date_start' => $this->date,
    //         'date_end' => $this->date->copy()->add(2, 'day'),
    //         'quantity' => 1,
    //         'status' => 'pending',
    //     ]);

    //     // Check that the child reservation is linked in child reservations table
    //     $this->assertDatabaseHas('resrv_child_reservations', [
    //         'reservation_id' => 1,
    //         'child_reservation_id' => 2,
    //     ]);
    // }

    // // Test that it handles multiple items in checkout
    // public function test_handles_multiple_items_in_checkout()
    // {
    //     $entry = Entry::make()
    //         ->collection('pages')
    //         ->slug('checkout')
    //         ->data(['title' => 'Checkout']);

    //     $entry->save();

    //     Config::set('resrv-config.checkout_entry', $entry->id());

    //     // Create additional availability for a different date range
    //     Availability::factory()
    //         ->count(4)
    //         ->sequence(
    //             ['date' => $this->date->copy()->addDays(5)->startOfDay()],
    //             ['date' => $this->date->copy()->addDays(6)->startOfDay()],
    //             ['date' => $this->date->copy()->addDays(7)->startOfDay()],
    //             ['date' => $this->date->copy()->addDays(8)->startOfDay()],
    //         )
    //         ->create([
    //             'statamic_id' => $this->entries->first()->id(),
    //             'price' => 75,
    //             'available' => 2,
    //         ]);

    //     $availabilityData1 = [
    //         'dates' => [
    //             'date_start' => $this->date->toISOString(),
    //             'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
    //         ],
    //         'quantity' => 1,
    //         'advanced' => null,
    //     ];

    //     $availabilityData2 = [
    //         'dates' => [
    //             'date_start' => $this->date->copy()->addDays(5)->toISOString(),
    //             'date_end' => $this->date->copy()->addDays(7)->toISOString(),
    //         ],
    //         'quantity' => 2,
    //         'advanced' => null,
    //     ];

    //     $results1 = app(Availability::class)->getAvailabilityForEntry($availabilityData1, $this->entries->first()->id());
    //     $results2 = app(Availability::class)->getAvailabilityForEntry($availabilityData2, $this->entries->first()->id());

    //     // Add items to cart
    //     $component = Livewire::test(AvailabilityCart::class)
    //         ->dispatch('add-to-cart',
    //             entryId: $this->entries->first()->id(),
    //             availabilityData: $availabilityData1,
    //             results: $results1
    //         )
    //         ->dispatch('add-to-cart',
    //             entryId: $this->entries->first()->id(),
    //             availabilityData: $availabilityData2,
    //             results: $results2
    //         )
    //         ->call('checkout');

    //     // Check that we have 1 parent and 2 child reservations
    //     $this->assertDatabaseCount('resrv_reservations', 3);
    //     $this->assertDatabaseCount('resrv_child_reservations', 2);

    //     // Check that both child reservations point to the parent
    //     $this->assertDatabaseHas('resrv_child_reservations', [
    //         'reservation_id' => 1,
    //         'child_reservation_id' => 2,
    //     ]);

    //     $this->assertDatabaseHas('resrv_child_reservations', [
    //         'reservation_id' => 1,
    //         'child_reservation_id' => 3,
    //     ]);
    // }

    // // Test that it prevents checkout if an item is invalid
    // public function test_prevents_checkout_if_item_is_invalid()
    // {
    //     $entry = Entry::make()
    //         ->collection('pages')
    //         ->slug('checkout')
    //         ->data(['title' => 'Checkout']);

    //     $entry->save();

    //     Config::set('resrv-config.checkout_entry', $entry->id());

    //     $availabilityData = [
    //         'dates' => [
    //             'date_start' => $this->date->toISOString(),
    //             'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
    //         ],
    //         'quantity' => 1,
    //         'advanced' => null,
    //     ];

    //     $results = app(Availability::class)->getAvailabilityForEntry($availabilityData, $this->entries->first()->id());

    //     // Create component with a valid item
    //     $component = Livewire::test(AvailabilityCart::class)
    //         ->dispatch('add-to-cart',
    //             entryId: $this->entries->first()->id(),
    //             availabilityData: $availabilityData,
    //             results: $results
    //         );

    //     // Get the item ID
    //     $itemId = $component->get('cart.items')->keys()->first();

    //     // Invalidate the item
    //     $component->dispatch('invalidate-cart-item', itemId: $itemId);

    //     // Try to checkout
    //     $component->call('checkout')
    //         ->assertSessionHas('error')
    //         ->assertSessionMissing('resrv_reservation');
    // }

    // // Test that availability is decremented after checkout
    // public function test_availability_is_decremented_after_checkout()
    // {
    //     $entry = Entry::make()
    //         ->collection('pages')
    //         ->slug('checkout')
    //         ->data(['title' => 'Checkout']);

    //     $entry->save();

    //     Config::set('resrv-config.checkout_entry', $entry->id());

    //     // Before checkout, availability should be 1
    //     $this->assertDatabaseHas('resrv_availabilities', [
    //         'statamic_id' => $this->entries->first()->id(),
    //         'date' => $this->date->startOfDay(),
    //         'available' => 1,
    //     ]);

    //     $availabilityData = [
    //         'dates' => [
    //             'date_start' => $this->date->toISOString(),
    //             'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
    //         ],
    //         'quantity' => 1,
    //         'advanced' => null,
    //     ];

    //     $results = app(Availability::class)->getAvailabilityForEntry($availabilityData, $this->entries->first()->id());

    //     // Add item to cart and checkout
    //     $component = Livewire::test(AvailabilityCart::class)
    //         ->dispatch('add-to-cart',
    //             entryId: $this->entries->first()->id(),
    //             availabilityData: $availabilityData,
    //             results: $results
    //         )
    //         ->call('checkout');

    //     // After checkout, availability should be 0
    //     $this->assertDatabaseHas('resrv_availabilities', [
    //         'statamic_id' => $this->entries->first()->id(),
    //         'date' => $this->date->startOfDay(),
    //         'available' => 0,
    //     ]);
    // }

    // // Test that cart is cleared after successful checkout
    // public function test_cart_is_cleared_after_successful_checkout()
    // {
    //     $entry = Entry::make()
    //         ->collection('pages')
    //         ->slug('checkout')
    //         ->data(['title' => 'Checkout']);

    //     $entry->save();

    //     Config::set('resrv-config.checkout_entry', $entry->id());

    //     $availabilityData = [
    //         'dates' => [
    //             'date_start' => $this->date->toISOString(),
    //             'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
    //         ],
    //         'quantity' => 1,
    //         'advanced' => null,
    //     ];

    //     $results = app(Availability::class)->getAvailabilityForEntry($availabilityData, $this->entries->first()->id());

    //     // Create fresh component with persistent state
    //     $component = Livewire::test(AvailabilityCart::class, [], ['resrv-cart'])
    //         ->dispatch('add-to-cart',
    //             entryId: $this->entries->first()->id(),
    //             availabilityData: $availabilityData,
    //             results: $results
    //         )
    //         ->assertViewHas('itemCount', 1)
    //         ->call('checkout');

    //     // Create another component instance, cart should be empty
    //     Livewire::test(AvailabilityCart::class, [], ['resrv-cart'])
    //         ->assertViewHas('itemCount', 0)
    //         ->assertSee(trans('statamic-resrv::frontend.reservationsEmpty'));
    // }

    // Helper function to calculate expected price
    private function calculateTotalPrice($results)
    {
        return number_format((float) str_replace(',', '', $results['data']['price']), 2, '.', '');
    }

    // Helper function to create the availability array
    private function availabilityArray($availabilityData)
    {
        return [
            'date_start' => $availabilityData['dates']['date_start'],
            'date_end' => $availabilityData['dates']['date_end'],
            'quantity' => $availabilityData['quantity'],
            'advanced' => $availabilityData['advanced'],
        ];
    }
}
