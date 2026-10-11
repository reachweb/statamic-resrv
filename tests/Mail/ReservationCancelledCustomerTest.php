<?php

namespace Reach\StatamicResrv\Tests\Mail;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Jobs\SendCustomerCancelledEmail;
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

        // An opened-but-unpaid intent leaves a payment_id behind; the email must not claim the payment is non-refundable.
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

    public function test_hold_lapsed_email_names_the_deadline_and_the_stay_in_plain_words()
    {
        $item = $this->makeStatamicItem();
        $deadline = now()->subHour()->setSecond(0);

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'cancelled',
            'date_start' => now()->addDays(5)->setTime(14, 0),
            'date_end' => now()->addDays(7)->setTime(11, 0),
            'hold_expires_at' => $deadline,
        ])->withCustomer()->create();

        $html = (new ReservationCancelledCustomer($reservation, ReservationCancelled::CONTEXT_HOLD_LAPSED))->render();

        $this->assertStringContainsString('We did not receive your payment by '.$deadline->format('d-m-Y H:i T').',', $html);
        $this->assertStringNotContainsString('payment hold lapsed', $html);
        $this->assertStringContainsString('Reservation details', $html);
        $this->assertStringContainsString($reservation->date_start->format('d-m-Y H:i'), $html);
        $this->assertStringContainsString($reservation->date_end->format('d-m-Y H:i'), $html);
        $this->assertStringContainsString($item->get('title'), $html);
        $this->assertStringContainsString('Cancelled on', $html);
    }

    public function test_hold_lapsed_email_without_a_recorded_deadline_still_reads_plainly()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'cancelled',
            'hold_expires_at' => null,
        ])->withCustomer()->create();

        $html = (new ReservationCancelledCustomer($reservation, ReservationCancelled::CONTEXT_HOLD_LAPSED))->render();

        $this->assertStringContainsString('We did not receive your payment in time', $html);
        $this->assertStringContainsString('Reservation details', $html);
    }

    public function test_generic_cancellations_keep_their_body()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'cancelled',
        ])->withCustomer()->create();

        $html = (new ReservationCancelledCustomer($reservation))->render();

        $this->assertStringContainsString('Your reservation has been cancelled.', $html);
        $this->assertStringNotContainsString('did not receive your payment', $html);
        $this->assertStringNotContainsString('Reservation details', $html);
    }

    public function test_the_queued_job_handles_payloads_serialized_before_the_context_property_existed()
    {
        Mail::fake();

        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'cancelled',
        ])->withCustomer()->create();

        // A job serialized by a pre-$context release has no `context` key: handle() must send, not throw on the uninitialized typed property.
        // newInstanceWithoutConstructor mirrors SerializesModels::__unserialize, which never runs the constructor.
        $job = (new \ReflectionClass(SendCustomerCancelledEmail::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(SendCustomerCancelledEmail::class, 'reservation'))->setValue($job, $reservation);

        $job->handle();

        Mail::assertSent(ReservationCancelledCustomer::class, fn ($mail) => $mail->hasTo($reservation->customer->email));
    }
}
