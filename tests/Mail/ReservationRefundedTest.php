<?php

namespace Reach\StatamicResrv\Tests\Mail;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
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
            'payment_id' => 'pi_123',
        ])->withCustomer()->create();

        $html = (new ReservationRefunded($reservation))->render();

        $this->assertStringContainsString('Refunded to your card', $html);
        // The amount refunded is exactly what was charged to the card (totalToCharge =
        // payment + payment_surcharge, the PaymentIntent amount), not the full reservation price.
        $this->assertStringContainsString($reservation->totalToCharge(), $html);
        $this->assertStringNotContainsString('returned to you separately', $html);
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
            'payment_id' => 'pi_123',
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
        $this->assertStringContainsString('Your reservation has been cancelled.', $html);
        $this->assertStringNotContainsString('returned to you separately', $html);
    }

    public function test_a_payment_recorded_out_of_band_is_promised_back_separately()
    {
        $item = $this->makeStatamicItem();

        // A CP-created booking marked as paid: no gateway reference, so nothing returns it automatically.
        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'refunded',
            'price' => '45.00',
            'payment' => '45.00',
            'payment_id' => '',
            'created_by' => '1',
        ])->withCustomer()->create();

        $html = (new ReservationRefunded($reservation))->render();

        $this->assertStringContainsString('Your reservation has been cancelled.', $html);
        $this->assertStringContainsString('Your payment of '.config('resrv-config.currency_symbol').' 45.00 will be returned to you separately.', $html);
        $this->assertStringNotContainsString('Refunded to your card', $html);
        $this->assertStringNotContainsString('If you have already paid', $html);
    }

    public function test_a_refunded_frontend_booking_that_never_collected_money_promises_nothing()
    {
        $item = $this->makeStatamicItem();

        // Refunded from PENDING before any intent existed: the system records nothing received.
        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'refunded',
            'price' => '45.00',
            'payment' => '45.00',
            'payment_id' => '',
            'payment_gateway' => '',
            'created_by' => null,
        ])->withCustomer()->create();

        $html = (new ReservationRefunded($reservation))->render();

        $this->assertStringContainsString('Your reservation has been cancelled.', $html);
        $this->assertStringNotContainsString('returned to you separately', $html);
        $this->assertStringNotContainsString('Refunded to your card', $html);
    }

    public function test_an_unresolved_reference_never_reads_as_a_card_refund()
    {
        $item = $this->makeStatamicItem();

        // Settled out of band with a kept intent reference: the refund may have skipped the gateway.
        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'refunded',
            'price' => '60.00',
            'payment' => '60.00',
            'payment_id' => 'pi_kept',
            'payment_unresolved' => true,
        ])->withCustomer()->create();

        $mail = new ReservationRefunded($reservation);
        $html = $mail->render();

        $mail->assertHasSubject('Reservation Cancelled');
        $this->assertStringNotContainsString('Refunded to your card', $html);
        $this->assertStringContainsString('Your payment of '.config('resrv-config.currency_symbol').' 60.00 will be returned to you separately.', $html);
    }

    public function test_an_offline_gateway_refund_hedges_on_whether_the_payment_arrived()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake' => ['class' => FakePaymentGateway::class, 'label' => 'Fake Online'],
            'offline' => ['class' => OfflinePaymentGateway::class, 'label' => 'Bank Transfer'],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $item = $this->makeStatamicItem();

        // An offline checkout confirms before the transfer arrives, so its reference proves nothing was received.
        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'refunded',
            'price' => '80.00',
            'payment' => '80.00',
            'payment_gateway' => 'offline',
            'payment_id' => 'offline_abc',
        ])->withCustomer()->create();

        $html = (new ReservationRefunded($reservation))->render();

        $this->assertStringContainsString('If you have already paid for this reservation, your payment of '.config('resrv-config.currency_symbol').' 80.00 will be returned to you separately.', $html);
        $this->assertStringNotContainsString('Refunded to your card', $html);
    }

    public function test_an_offline_booking_marked_paid_in_the_cp_is_promised_back_like_the_cp_says()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake' => ['class' => FakePaymentGateway::class, 'label' => 'Fake Online'],
            'offline' => ['class' => OfflinePaymentGateway::class, 'label' => 'Bank Transfer'],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $item = $this->makeStatamicItem();

        // Created in the CP and marked as paid: the admin recorded the transfer, so the CP tells
        // them to return it — the customer email must not hedge on whether it arrived.
        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'refunded',
            'price' => '80.00',
            'payment' => '80.00',
            'payment_gateway' => 'offline',
            'payment_id' => 'offline_abc',
            'created_by' => 'admin-id',
        ])->withCustomer()->create();

        $this->assertSame('80.00', $reservation->amountReceived()?->format());

        $html = (new ReservationRefunded($reservation))->render();

        $this->assertStringContainsString('Your payment of '.config('resrv-config.currency_symbol').' 80.00 will be returned to you separately.', $html);
        $this->assertStringNotContainsString('If you have already paid', $html);
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
            $this->assertStringContainsString('Your reservation has been cancelled.', $html, "Mode [$mode] dropped the cancellation notice for a partner reservation.");
        }
    }
}
