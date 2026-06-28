<?php

namespace Reach\StatamicResrv\Tests\Mail;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Mail\ReservationConfirmed;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationConfirmedTest extends TestCase
{
    use RefreshDatabase;

    // A confirmation can be resent long after booking, by which point the entry may have been
    // deleted. entry() then returns the emptyEntry() array, so the template must not dereference
    // it as an object.
    public function test_it_renders_when_the_reservation_entry_has_been_deleted()
    {
        $reservation = Reservation::factory([
            'item_id' => 'deleted-entry-id',
            'status' => 'confirmed',
        ])->withCustomer()->create();

        $html = (new ReservationConfirmed($reservation))->render();

        $this->assertStringContainsString('## Entry deleted ##', $html);
    }

    // Option values are soft-deleted to preserve reservation history. A resent confirmation must
    // still render (and keep showing the value the customer originally booked).
    public function test_it_renders_when_an_option_value_has_been_soft_deleted()
    {
        $item = $this->makeStatamicItem();

        $option = Option::factory()->create(['item_id' => $item->id()]);
        $value = OptionValue::factory()->create(['option_id' => $option->id]);

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->withCustomer()->create();

        $reservation->options()->attach($option->id, ['value' => $value->id]);

        $value->delete();

        $html = (new ReservationConfirmed($reservation))->render();

        $this->assertStringContainsString($option->name, $html);
        $this->assertStringContainsString($value->name, $html);
    }
}
