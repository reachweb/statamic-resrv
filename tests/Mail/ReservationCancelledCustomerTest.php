<?php

namespace Reach\StatamicResrv\Tests\Mail;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Mail\ReservationCancelledCustomer;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationCancelledCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelled_email_for_a_paid_booking_emphasizes_that_no_refund_was_issued()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'cancelled',
            'price' => '200.00',
            'payment' => '120.00',
            'payment_id' => 'pi_123',
        ])->withCustomer()->create();

        $html = (new ReservationCancelledCustomer($reservation))->render();

        $this->assertStringContainsString('Your reservation has been cancelled.', $html);
        $this->assertStringContainsString('No refund has been issued for this cancellation.', $html);
        $this->assertStringNotContainsString('nothing to refund', $html);
    }

    public function test_cancelled_email_for_a_no_charge_booking_does_not_claim_a_withheld_payment()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'cancelled',
            'price' => '200.00',
            'payment' => '137.00',
            'payment_id' => '',
        ])->withCustomer()->create();

        $html = (new ReservationCancelledCustomer($reservation))->render();

        // A partner / zero-charge booking collected nothing, so telling the customer their
        // payment is being withheld would be false.
        $this->assertStringContainsString('Your reservation has been cancelled.', $html);
        $this->assertStringContainsString('nothing to refund', $html);
        $this->assertStringNotContainsString('No refund has been issued', $html);
    }

    public function test_cancelled_email_for_an_unpaid_hold_does_not_claim_a_withheld_payment()
    {
        $item = $this->makeStatamicItem();

        // An awaiting-payment reservation whose customer opened the pay link leaves a payment_id
        // behind for an unpaid, later-voided intent. Cancelling it must NOT tell the customer their
        // payment is non-refundable — nothing was ever captured (the webhook is the only capture
        // path, and it confirms rather than leaving the booking awaiting).
        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'cancelled',
            'price' => '200.00',
            'payment' => '120.00',
            'payment_id' => 'pi_unpaid',
        ])->withCustomer()->create();

        $html = (new ReservationCancelledCustomer($reservation, ReservationCancelled::CONTEXT_UNPAID_HOLD))->render();

        $this->assertStringContainsString('nothing to refund', $html);
        $this->assertStringNotContainsString('No refund has been issued', $html);
    }
}
