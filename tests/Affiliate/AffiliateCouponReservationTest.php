<?php

namespace Reach\StatamicResrv\Tests\Affiliate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Reach\StatamicResrv\Enums\AffiliateAttributionSource;
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

        // Check that the affiliate is associated with the reservation, marked as coupon-sourced
        $this->assertDatabaseHas('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
            'affiliate_id' => $affiliate->id,
            'fee' => 10,
            'source' => AffiliateAttributionSource::Coupon->value,
        ]);
    }

    // With the affiliate system disabled the coupon still discounts, but no commission
    // attribution is created — same contract as an unpublished affiliate.
    public function test_affiliate_is_not_associated_when_affiliates_are_disabled()
    {
        Config::set('resrv-config.enable_affiliates', false);

        $item = $this->makeStatamicItem();

        $coupon = DynamicPricing::factory()->create(['coupon' => 'AFFILIATE10']);
        $coupon->entries()->sync([$item->id()]);

        $affiliate = Affiliate::factory()->create(['fee' => 10]);
        $affiliate->coupons()->sync([$coupon->id]);

        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
        ]);

        CouponUpdated::dispatch($reservation, 'AFFILIATE10');

        $this->assertDatabaseMissing('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
            'affiliate_id' => $affiliate->id,
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

        // Create a reservation and associate the affiliate as if the cookie path attributed it
        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
        ]);
        $reservation->affiliate()->attach($affiliate->id, [
            'fee' => 10,
            'source' => AffiliateAttributionSource::Cookie->value,
        ]);

        // Dispatch the CouponUpdated event again
        CouponUpdated::dispatch($reservation, 'AFFILIATE10');

        // Check that the affiliate is only associated once and the cookie attribution
        // was not downgraded to coupon-sourced
        $count = $reservation->affiliate()->where('affiliate_id', $affiliate->id)->count();
        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
            'affiliate_id' => $affiliate->id,
            'source' => AffiliateAttributionSource::Cookie->value,
        ]);
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

    public function test_unpublished_affiliate_does_not_get_attributed_from_coupon()
    {
        $item = $this->makeStatamicItem();

        // Create a coupon owned by an unpublished (disabled) affiliate
        $coupon = DynamicPricing::factory()->create(['coupon' => 'AFFILIATE10']);
        $coupon->entries()->sync([$item->id()]);

        $affiliate = Affiliate::factory()->create(['fee' => 10, 'published' => false]);
        $affiliate->coupons()->sync([$coupon->id]);

        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
        ]);

        CouponUpdated::dispatch($reservation, 'AFFILIATE10');

        // The coupon itself keeps working, but no commission attribution is created
        $this->assertDatabaseMissing('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
            'affiliate_id' => $affiliate->id,
        ]);
    }

    public function test_removing_coupon_detaches_attribution_of_a_later_unpublished_affiliate()
    {
        $item = $this->makeStatamicItem();

        $coupon = DynamicPricing::factory()->create(['coupon' => 'AFFILIATE10']);
        $coupon->entries()->sync([$item->id()]);

        $affiliate = Affiliate::factory()->create(['fee' => 10]);
        $affiliate->coupons()->sync([$coupon->id]);

        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
        ]);

        // Attribution created while the affiliate was published
        CouponUpdated::dispatch($reservation, 'AFFILIATE10');
        $this->assertDatabaseHas('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
            'affiliate_id' => $affiliate->id,
        ]);

        // The affiliate gets disabled, then the customer removes the coupon
        $affiliate->update(['published' => false]);
        CouponUpdated::dispatch($reservation, 'AFFILIATE10', true);

        // The stale coupon attribution must still be cleaned up
        $this->assertDatabaseMissing('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
            'affiliate_id' => $affiliate->id,
        ]);
    }

    public function test_removing_coupon_keeps_cookie_sourced_attribution()
    {
        $item = $this->makeStatamicItem();

        // Create a coupon
        $coupon = DynamicPricing::factory()->create(['coupon' => 'AFFILIATE10']);
        $coupon->entries()->sync([$item->id()]);

        // Create an affiliate and associate the coupon
        $affiliate = Affiliate::factory()->create(['fee' => 10]);
        $affiliate->coupons()->sync([$coupon->id]);

        // Create a reservation attributed to the affiliate through their cookie (link visit)
        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
        ]);
        $reservation->affiliate()->attach($affiliate->id, [
            'fee' => 10,
            'source' => AffiliateAttributionSource::Cookie->value,
        ]);

        // The customer enters and then removes the same affiliate's coupon
        CouponUpdated::dispatch($reservation, 'AFFILIATE10');
        CouponUpdated::dispatch($reservation, 'AFFILIATE10', true);

        // The cookie attribution must survive the coupon removal
        $this->assertDatabaseHas('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
            'affiliate_id' => $affiliate->id,
            'source' => AffiliateAttributionSource::Cookie->value,
        ]);
    }

    public function test_removing_coupon_detaches_only_the_coupon_sourced_affiliate()
    {
        $item = $this->makeStatamicItem();

        // Create a coupon owned by affiliate B
        $coupon = DynamicPricing::factory()->create(['coupon' => 'AFFILIATE10']);
        $coupon->entries()->sync([$item->id()]);

        $affiliateA = Affiliate::factory()->create(['fee' => 10, 'code' => 'AFF-A']);
        $affiliateB = Affiliate::factory()->create(['fee' => 15, 'code' => 'AFF-B']);
        $affiliateB->coupons()->sync([$coupon->id]);

        // The reservation was attributed to affiliate A through their cookie
        $reservation = Reservation::factory()->create([
            'item_id' => $item->id(),
        ]);
        $reservation->affiliate()->attach($affiliateA->id, [
            'fee' => 10,
            'source' => AffiliateAttributionSource::Cookie->value,
        ]);

        // The customer enters and then removes affiliate B's coupon
        CouponUpdated::dispatch($reservation, 'AFFILIATE10');
        CouponUpdated::dispatch($reservation, 'AFFILIATE10', true);

        // Affiliate B's coupon attribution is gone, affiliate A's cookie attribution stays
        $this->assertDatabaseMissing('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
            'affiliate_id' => $affiliateB->id,
        ]);
        $this->assertDatabaseHas('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
            'affiliate_id' => $affiliateA->id,
            'source' => AffiliateAttributionSource::Cookie->value,
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
