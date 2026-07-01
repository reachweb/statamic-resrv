<?php

namespace Reach\StatamicResrv\Tests\Browser;

use Laravel\Dusk\Browser;

/**
 * Coupon / dynamic-pricing reactivity on the checkout totals. The 328 headless tests
 * own the pricing math; this proves the payment table reflects a coupon LIVE in a real
 * browser: applying a coupon (checkout-coupon.blade → addCoupon → coupon-applied →
 * updateTotals) changes the total, and removing it (coupon-removed) reverts it. Both a
 * percentage and a flat coupon are exercised to show the UI reflects each.
 *
 * The seed attaches two coupons to the bookable entry — `SAVE20` (20% off) and `SAVE5`
 * (flat 5 off). Coupons only discount while their code is the active session coupon, so
 * they never move the base totals the other funnel tasks assert.
 */
class CouponPricingTest extends BrowserTestCase
{
    public function test_percentage_and_flat_coupons_change_then_revert_the_total(): void
    {
        $this->browse(function (Browser $browser) {
            $this->reachCheckoutStepOne($browser);

            $this->assertCouponChangesThenReverts($browser, 'SAVE20'); // percentage
            $this->assertCouponChangesThenReverts($browser, 'SAVE5');  // flat
        });
    }

    /**
     * Apply a coupon and assert the payment-table total changes live, then remove it and
     * assert the total reverts to exactly what it was before.
     */
    private function assertCouponChangesThenReverts(Browser $browser, string $code): void
    {
        $browser->waitFor('@coupon-toggle');
        $before = $browser->text('@payment-total');

        // Open the coupon field, type the code, apply with Enter (the input's
        // keyup.enter → $wire.addCoupon). coupon-applied → updateTotals re-prices the
        // reservation and the payment table morphs to the discounted total.
        $browser->click('@coupon-toggle')
            ->waitFor('@coupon-input')
            ->type('@coupon-input', $code)
            ->keys('@coupon-input', '{ENTER}');

        $browser->waitUsing(6, 100, fn () => $browser->text('@payment-total') !== $before);
        $this->assertNotSame($before, $browser->text('@payment-total'), "Applying {$code} should change the total.");

        // Removing the coupon reverts the total.
        $browser->waitFor('@coupon-remove')
            ->click('@coupon-remove')
            ->waitUsing(6, 100, fn () => $browser->text('@payment-total') === $before);
        $this->assertSame($before, $browser->text('@payment-total'), "Removing {$code} should revert the total.");
    }

    /**
     * Search → Book Now → /checkout step 1, where the coupon field and payment table
     * both render in the sidebar.
     */
    private function reachCheckoutStepOne(Browser $browser): void
    {
        $browser->visit('/bookable')->waitFor('[name=datepicker]')
            ->click('[name=datepicker]')->waitFor('.rc-day__label')
            ->click('.rc-day--available:not(.rc-day--hidden)')->waitFor('[wire\\:click="checkout()"]')
            ->click('[wire\\:click="checkout()"]')
            ->waitForLocation('/checkout')
            ->waitFor('@payment-total')
            ->waitFor('@coupon-toggle');
    }
}
