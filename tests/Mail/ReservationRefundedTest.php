<?php

namespace Reach\StatamicResrv\Tests\Mail;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Reach\StatamicResrv\Mail\ReservationRefunded;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationRefundedTest extends TestCase
{
    use RefreshDatabase;

    public function test_refunded_email_for_a_paid_reservation_shows_the_charged_amount()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'refunded',
            'price' => '200.00',
            'payment' => '120.00',
        ])->withCustomer()->create();

        $html = (new ReservationRefunded($reservation))->render();

        $this->assertStringContainsString('Refunded to your card', $html);
        // The amount refunded is exactly what was charged to the card (totalToCharge =
        // payment + payment_surcharge, the PaymentIntent amount), not the full reservation price.
        $this->assertStringContainsString($reservation->totalToCharge(), $html);
    }

    public function test_refunded_email_includes_the_payment_surcharge_in_the_refunded_amount()
    {
        // Stripe refunds the whole PaymentIntent, which was charged for payment + payment_surcharge.
        // The email must report that full amount, not just the base payment, or it under-reports.
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'refunded',
            'price' => '200.00',
            'payment' => '120.00',
            'payment_surcharge' => '5.00',
        ])->withCustomer()->create();

        $html = (new ReservationRefunded($reservation))->render();

        $this->assertStringContainsString($reservation->totalToCharge(), $html);
        $this->assertStringNotContainsString($reservation->payment->format(), $html);
    }

    public function test_refunded_email_for_a_partner_reservation_does_not_claim_a_card_refund()
    {
        // An affiliate "pay later" (skip-payment) booking lands in the PARTNER state with its
        // payment field still populated, even though no card was ever charged. The refunded email
        // must not tell the customer that amount was "Refunded to your card".
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'partner',
            'price' => '200.00',
            'payment' => '137.00',
        ])->withCustomer()->create();

        $html = (new ReservationRefunded($reservation))->render();

        $this->assertStringNotContainsString('Refunded to your card', $html);
        $this->assertStringNotContainsString($reservation->payment->format(), $html);
        $this->assertStringContainsString('No payment was collected for this reservation', $html);
    }

    public function test_partner_guard_holds_regardless_of_payment_mode()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'partner',
            'price' => '200.00',
            'payment' => '50.00',
        ])->withCustomer()->create();

        foreach (['full', 'everything', 'fixed', 'percent'] as $mode) {
            Config::set('resrv-config.payment', $mode);

            $html = (new ReservationRefunded($reservation))->render();

            $this->assertStringNotContainsString('Refunded to your card', $html, "Mode [$mode] still claimed a card refund for a partner reservation.");
            $this->assertStringContainsString('No payment was collected for this reservation', $html, "Mode [$mode] dropped the no-payment notice for a partner reservation.");
        }
    }
}
