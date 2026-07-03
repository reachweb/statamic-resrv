<?php

namespace Reach\StatamicResrv\Tests\Affiliate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class CancelledAtBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function migration(): object
    {
        return include __DIR__.'/../../database/migrations/2026_06_17_000000_add_cancelled_at_to_resrv_reservation_affiliate.php';
    }

    protected function makeAffiliateReservation(Affiliate $affiliate, array $attributes = []): Reservation
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory()->withCustomer()->create(array_merge([
            'item_id' => $item->id(),
        ], $attributes));

        $reservation->affiliate()->attach($affiliate->id, ['fee' => 20]);

        return $reservation;
    }

    protected function pivotCancelledAt(int $reservationId): ?string
    {
        return DB::table('resrv_reservation_affiliate')
            ->where('reservation_id', $reservationId)
            ->value('cancelled_at');
    }

    public function test_backfills_cancelled_at_for_pivots_of_already_refunded_reservations()
    {
        $affiliate = Affiliate::factory()->create(['fee' => 20]);

        // Pre-migration data: the refund happened before the column existed, so nothing
        // ever stamped the pivot — without the backfill it would report as payable.
        $refunded = $this->makeAffiliateReservation($affiliate, ['status' => 'refunded']);
        $confirmed = $this->makeAffiliateReservation($affiliate, ['status' => 'confirmed']);
        $partner = $this->makeAffiliateReservation($affiliate, ['status' => 'partner']);

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $refundedAt = DB::table('resrv_reservations')->where('id', $refunded->id)->value('updated_at');

        $this->assertSame($refundedAt, $this->pivotCancelledAt($refunded->id));
        $this->assertNull($this->pivotCancelledAt($confirmed->id));
        $this->assertNull($this->pivotCancelledAt($partner->id));
    }

    public function test_backfill_covers_multiple_pivots_on_the_same_refunded_reservation()
    {
        $affiliate = Affiliate::factory()->create(['fee' => 20]);
        $other = Affiliate::factory()->create(['fee' => 10, 'code' => 'OTHER']);

        // A reservation can carry two attributions (cookie + coupon) — both commissions
        // were voided by the historical refund, so both pivots must be stamped.
        $refunded = $this->makeAffiliateReservation($affiliate, ['status' => 'refunded']);
        $refunded->affiliate()->attach($other->id, ['fee' => 10]);

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $this->assertSame(2, DB::table('resrv_reservation_affiliate')
            ->where('reservation_id', $refunded->id)
            ->whereNotNull('cancelled_at')
            ->count());
    }
}
