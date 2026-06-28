<?php

namespace Reach\StatamicResrv\Tests\Mail;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Mail\ReservationMade;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationMadeTest extends TestCase
{
    use RefreshDatabase;

    // The admin notification must render even when the entry it referenced has since been deleted;
    // entry() then returns the emptyEntry() array, which the template must not treat as an object.
    public function test_it_renders_when_the_reservation_entry_has_been_deleted()
    {
        $reservation = Reservation::factory([
            'item_id' => 'deleted-entry-id',
            'status' => 'confirmed',
        ])->withCustomer()->create();

        $html = (new ReservationMade($reservation))->render();

        $this->assertStringContainsString('## Entry deleted ##', $html);
    }

    // Option values are soft-deleted to preserve history; the notification must still render and
    // keep showing the value that was originally booked.
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

        $html = (new ReservationMade($reservation))->render();

        $this->assertStringContainsString($option->name, $html);
        $this->assertStringContainsString($value->name, $html);
    }
}
