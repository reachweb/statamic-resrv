<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Livewire\AvailabilityCart;
use Reach\StatamicResrv\Livewire\AvailabilityResults;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
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
            ->assertSee($results['data']['price'])
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

    // Test that it creates parent reservation with multiple child reservations on checkout
    public function test_creates_reservations_when_checkout_is_called()
    {
        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());

        // First item data
        $availabilityData1 = [
            'dates' => [
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
            ],
            'quantity' => 1,
            'advanced' => null,
        ];

        $results1 = app(Availability::class)->getAvailabilityForEntry($this->availabilityArray($availabilityData1), $this->entries->first()->id());

        // Second item data
        $availabilityData2 = [
            'dates' => [
                'date_start' => $this->date->copy()->toISOString(),
                'date_end' => $this->date->copy()->addDays(3)->toISOString(),
            ],
            'quantity' => 1,
            'advanced' => null,
        ];

        $results2 = app(Availability::class)->getAvailabilityForEntry($this->availabilityArray($availabilityData2), $this->entries->first()->id());

        // Add both items to cart and checkout
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
            ->call('checkout')
            ->assertSessionHas('resrv_reservation', 1);

        // Expected total price (sum of both reservations)
        $expectedTotalPrice = $results1['data']['price'] + $results2['data']['price'];

        // Check that a parent reservation was created with the combined price
        $this->assertDatabaseHas('resrv_reservations',
            [
                'type' => 'parent',
                'status' => 'pending',
                'price' => $expectedTotalPrice,
            ]
        );

        // Check that the first child reservation was created and linked to the parent
        $this->assertDatabaseHas('resrv_child_reservations',
            [
                'item_id' => ResrvEntry::whereItemId($this->entries->first()->id())->id,
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->add(2, 'day'),
                'quantity' => 1,
                'price' => (float) $results1['data']['price'],
            ]
        );

        // Check that the second child reservation was created and linked to the parent
        $this->assertDatabaseHas('resrv_child_reservations',
            [
                'item_id' => ResrvEntry::whereItemId($this->entries->first()->id())->id,
                'date_start' => $this->date,
                'date_end' => $this->date->copy()->addDays(3),
                'quantity' => 1,
                'price' => (float) $results2['data']['price'],
            ]
        );

        // Check that availability gets decreased for both date ranges
        $this->assertDatabaseHas('resrv_availabilities',
            [
                'statamic_id' => $this->entries->first()->id(),
                'date' => $this->date->startOfDay(),
                'available' => 0,
            ]
        );

        $this->assertDatabaseHas('resrv_availabilities',
            [
                'statamic_id' => $this->entries->first()->id(),
                'date' => $this->date->copy()->addDays(2)->startOfDay(),
                'available' => 0,
            ]
        );

        // Check that the reservation expires and availability is back
        $this->travel(15)->minutes();

        // Call availability to run the jobs
        Livewire::test(AvailabilityResults::class, ['entry' => $this->advancedEntries->first()->id()])
            ->dispatch('availability-search-updated',
                [
                    'dates' => [
                        'date_start' => $this->date->toISOString(),
                        'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
                    ],
                    'quantity' => 1,
                    'advanced' => null,
                ]
            );

        // Check that the reservation expires and availability gets decreased for both date ranges
        $this->assertDatabaseHas('resrv_reservations',
            [
                'type' => 'parent',
                'status' => 'expired',
            ]
        );
        $this->assertDatabaseHas('resrv_availabilities',
            [
                'statamic_id' => $this->entries->first()->id(),
                'date' => $this->date->startOfDay(),
                'available' => 1,
            ]
        );
        $this->assertDatabaseHas('resrv_availabilities',
            [
                'statamic_id' => $this->entries->first()->id(),
                'date' => $this->date->copy()->addDays(2)->startOfDay(),
                'available' => 1,
            ]
        );
    }

    // Test that it doesn't create a reservation if the availability has changed
    public function test_does_not_create_reservation_if_availability_changed()
    {
        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_entry', $entry->id());

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

        // Change availability manually
        Availability::where('statamic_id', $this->entries->first()->id())
            ->where('date', $this->date->startOfDay())
            ->update(['available' => 0]);

        // Try checkout and get validation error
        $component->call('checkout')
            ->assertHasErrors('availability');

        // Verify no reservation was created
        $this->assertDatabaseMissing('resrv_reservations', [
            'item_id' => $this->entries->first()->id(),
            'date_start' => $this->date,
        ]);
    }

    // Test that it creates a reservation and saves affiliate when the cookie is in the session
    public function test_creates_reservation_and_saves_affiliate_when_cookie_is_in_session()
    {
        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        $affiliate = Affiliate::factory()->create();

        Config::set('resrv-config.checkout_entry', $entry->id());

        $availabilityData = [
            'dates' => [
                'date_start' => $this->date->toISOString(),
                'date_end' => $this->date->copy()->add(2, 'day')->toISOString(),
            ],
            'quantity' => 1,
            'advanced' => null,
        ];

        $results = app(Availability::class)->getAvailabilityForEntry($this->availabilityArray($availabilityData), $this->entries->first()->id());

        // Add item to cart with affiliate cookie
        $component = Livewire::withCookies(['resrv_afid' => $affiliate->code])
            ->test(AvailabilityCart::class)
            ->dispatch('add-to-cart',
                entryId: $this->entries->first()->id(),
                availabilityData: $availabilityData,
                results: $results
            );

        $component->call('checkout');

        // Verify the affiliate was linked to the reservation
        $this->assertDatabaseHas('resrv_reservation_affiliate', [
            'reservation_id' => 1, // Parent reservation ID
            'affiliate_id' => $affiliate->id,
            'fee' => $affiliate->fee,
        ]);
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
