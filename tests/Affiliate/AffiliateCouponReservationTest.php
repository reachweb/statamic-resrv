<?php

namespace Reach\StatamicResrv\Tests\Affiliate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Events\CouponUpdated;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class AffiliateCouponReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_affiliate_is_associated_with_reservation_when_coupon_is_applied()
    {
        $item = $this->makeStatamicItem();

        // Create a coupon
        $coupon = DynamicPricing::factory()->create(['coupon' => 'AFFILIATE10']);
        $coupon->entries()->sync([$item->id()]);

        // Create an affiliate and associate the coupon
        $affiliate = Affiliate::factory()->create(['fee' => 10]);
        $affiliate->coupons()->sync([$coupon->id]);

        // Create a reservation
        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
        ]);

        // Dispatch the CouponUpdated event
        CouponUpdated::dispatch($reservation, 'AFFILIATE10');

        // Check that the affiliate is associated with the reservation
        $this->assertDatabaseHas('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
            'affiliate_id' => $affiliate->id,
            'fee' => 10,
        ]);
    }

    public function test_affiliate_is_not_associated_when_coupon_has_no_affiliate()
    {
        $item = $this->makeStatamicItem();

        // Create a coupon without an affiliate
        $coupon = DynamicPricing::factory()->create(['coupon' => 'NOAFFILIATE']);
        $coupon->entries()->sync([$item->id()]);

        // Create a reservation
        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
        ]);

        // Dispatch the CouponUpdated event
        CouponUpdated::dispatch($reservation, 'NOAFFILIATE');

        // Check that no affiliate is associated with the reservation
        $this->assertDatabaseMissing('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
        ]);
    }

    public function test_affiliate_is_not_duplicated_when_already_associated()
    {
        $item = $this->makeStatamicItem();

        // Create a coupon
        $coupon = DynamicPricing::factory()->create(['coupon' => 'AFFILIATE10']);
        $coupon->entries()->sync([$item->id()]);

        // Create an affiliate and associate the coupon
        $affiliate = Affiliate::factory()->create(['fee' => 10]);
        $affiliate->coupons()->sync([$coupon->id]);

        // Create a reservation and associate the affiliate
        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
        ]);
        $reservation->affiliate()->attach($affiliate->id, ['fee' => 10]);

        // Dispatch the CouponUpdated event again
        CouponUpdated::dispatch($reservation, 'AFFILIATE10');

        // Check that the affiliate is only associated once
        $count = $reservation->affiliate()->where('affiliate_id', $affiliate->id)->count();
        $this->assertEquals(1, $count);
    }

    public function test_affiliate_is_unassociated_when_coupon_is_removed()
    {
        $item = $this->makeStatamicItem();

        // Create a coupon
        $coupon = DynamicPricing::factory()->create(['coupon' => 'AFFILIATE10']);
        $coupon->entries()->sync([$item->id()]);

        // Create an affiliate and associate the coupon
        $affiliate = Affiliate::factory()->create(['fee' => 10]);
        $affiliate->coupons()->sync([$coupon->id]);

        // Create a reservation
        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
        ]);

        // First add the coupon/affiliate
        CouponUpdated::dispatch($reservation, 'AFFILIATE10');

        // Verify affiliate was associated
        $this->assertDatabaseHas('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
            'affiliate_id' => $affiliate->id,
        ]);

        // Dispatch the CouponUpdated event with remove flag
        CouponUpdated::dispatch($reservation, 'AFFILIATE10', true);

        // Verify affiliate was unassociated
        $this->assertDatabaseMissing('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
            'affiliate_id' => $affiliate->id,
        ]);
    }

    public function test_affiliate_with_correct_fee_is_associated()
    {
        $item = $this->makeStatamicItem();

        // Create a coupon
        $coupon = DynamicPricing::factory()->create(['coupon' => 'AFFILIATE25']);
        $coupon->entries()->sync([$item->id()]);

        // Create an affiliate with a specific fee
        $affiliate = Affiliate::factory()->create(['fee' => 25.50]);
        $affiliate->coupons()->sync([$coupon->id]);

        // Create a reservation
        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
        ]);

        // Dispatch the CouponUpdated event
        CouponUpdated::dispatch($reservation, 'AFFILIATE25');

        // Check that the affiliate is associated with the correct fee
        $this->assertDatabaseHas('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
            'affiliate_id' => $affiliate->id,
            'fee' => 25.50,
        ]);
    }

    public function test_affiliate_works_with_wildcard_coupons()
    {
        $item = $this->makeStatamicItem();

        // Create a wildcard coupon
        $coupon = DynamicPricing::factory()->create(['coupon' => 'AFFILIATE*']);
        $coupon->entries()->sync([$item->id()]);

        // Create an affiliate and associate the coupon
        $affiliate = Affiliate::factory()->create(['fee' => 15]);
        $affiliate->coupons()->sync([$coupon->id]);

        // Create a reservation
        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
        ]);

        // Dispatch the CouponUpdated event with a wildcard match
        CouponUpdated::dispatch($reservation, 'AFFILIATE123');

        // Check that the affiliate is associated with the reservation
        $this->assertDatabaseHas('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
            'affiliate_id' => $affiliate->id,
            'fee' => 15,
        ]);
    }
}
