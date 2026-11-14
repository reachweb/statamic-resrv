<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Filters\ReservationStatus;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_filters_by_the_given_statuses()
    {
        Reservation::factory()->create(['status' => 'confirmed']);
        Reservation::factory()->create(['status' => 'expired']);

        $query = Reservation::query();
        (new ReservationStatus)->apply($query, ['status' => ['confirmed']]);

        $this->assertEquals(['confirmed'], $query->pluck('status')->all());
    }

    // The values come straight from the request's decoded `filters` payload, so `status` can
    // arrive as null or be absent entirely — that must no-op, not TypeError on count(null).
    public function test_apply_ignores_null_or_missing_status_values()
    {
        Reservation::factory()->create(['status' => 'confirmed']);
        Reservation::factory()->create(['status' => 'expired']);

        $nullQuery = Reservation::query();
        (new ReservationStatus)->apply($nullQuery, ['status' => null]);

        $missingQuery = Reservation::query();
        (new ReservationStatus)->apply($missingQuery, []);

        $this->assertSame(2, $nullQuery->count());
        $this->assertSame(2, $missingQuery->count());
    }

    public function test_cancelled_is_offered_and_filterable()
    {
        Reservation::factory()->create(['status' => 'cancelled']);
        Reservation::factory()->create(['status' => 'confirmed']);

        $this->assertArrayHasKey(
            'cancelled',
            (new ReservationStatus)->fieldItems()['status']['options']
        );

        $query = Reservation::query();
        (new ReservationStatus)->apply($query, ['status' => ['cancelled']]);

        $this->assertEquals(['cancelled'], $query->pluck('status')->all());
    }

    public function test_badge_handles_null_status_values()
    {
        $filter = new ReservationStatus;

        $this->assertSame('Confirmed, Partner', $filter->badge(['status' => ['confirmed', 'partner']]));
        $this->assertSame('', $filter->badge(['status' => null]));
        $this->assertSame('', $filter->badge([]));
    }

    public function test_badge_uses_the_option_labels()
    {
        $filter = new ReservationStatus;

        $this->assertSame('Awaiting payment, Confirmed', $filter->badge(['status' => ['awaiting_payment', 'confirmed']]));
        $this->assertSame('Awaiting payment', $filter->fieldItems()['status']['options']['awaiting_payment']);
        // Legacy or unknown values are humanized rather than dropped.
        $this->assertSame('Some legacy', $filter->badge(['status' => ['some_legacy']]));
    }

    public function test_awaiting_payment_is_applied_by_default_but_abandoned_checkouts_are_not()
    {
        $defaults = (new ReservationStatus)->autoApply()['status'];

        $this->assertSame(['confirmed', 'partner', 'awaiting_payment'], $defaults);
        $this->assertNotContains('pending', $defaults);
        $this->assertNotContains('expired', $defaults);
    }
}
