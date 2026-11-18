<?php

namespace Reach\StatamicResrv\Tests\Mail;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Mail\ReservationConfirmed;
use Reach\StatamicResrv\Mail\ReservationMade;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationConfirmedTest extends TestCase
{
    use RefreshDatabase;

    // A confirmation can be resent long after booking, by which point the entry may have been
    // deleted. entry() then returns the emptyEntry() array, so the template must not dereference
    // it as an object.
    public function test_it_renders_when_the_reservation_entry_has_been_deleted()
    {
        $reservation = Reservation::factory([
            'item_id' => 'deleted-entry-id',
            'status' => 'confirmed',
        ])->withCustomer()->create();

        $html = (new ReservationConfirmed($reservation))->render();

        $this->assertStringContainsString('## Entry deleted ##', $html);
    }

    // Option values are soft-deleted to preserve reservation history. A resent confirmation must
    // still render (and keep showing the value the customer originally booked).
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

        $html = (new ReservationConfirmed($reservation))->render();

        $this->assertStringContainsString($option->name, $html);
        $this->assertStringContainsString($value->name, $html);
    }

    // Manual reservations request any amount whatever the global payment mode, so a partial
    // payment on an 'everything' site must still show what remains.
    public function test_a_partial_payment_shows_the_remaining_amount_even_in_everything_mode()
    {
        Config::set('resrv-config.payment', 'everything');

        $item = $this->makeStatamicItem();

        $partial = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'payment' => '30.00',
            'total' => '90.00',
        ])->withCustomer()->create();

        foreach ([new ReservationConfirmed($partial), new ReservationMade($partial)] as $mail) {
            $html = $mail->render();

            $this->assertStringContainsString('Remaining amount', $html);
            $this->assertStringContainsString('60.00', $html);
        }

        $full = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'payment' => '90.00',
            'total' => '90.00',
        ])->withCustomer()->create();

        foreach ([new ReservationConfirmed($full), new ReservationMade($full)] as $mail) {
            $this->assertStringNotContainsString('Remaining amount', $mail->render());
        }
    }

    // A checkout through an offline gateway (bank transfer) confirms before any money arrives, so
    // its confirmation is where the customer gets the payment details — not an "already paid" line.
    public function test_an_offline_checkout_confirmation_shows_the_amount_due_and_how_to_pay()
    {
        Config::set('resrv-config.payment_gateways', [
            'offline' => ['class' => OfflinePaymentGateway::class, 'label' => 'Bank Transfer'],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);
        // Greek υ is CF 85 in UTF-8: a line-break match that reads bytes splits it at 0x85 (NEL).
        Config::set('resrv-config.manual_reservations_offline_instructions', "Bank: ACME <b>Ltd</b>\nΔικαιούχος: Ευχαριστούμε ΑΕ\nIBAN: GR12 3456");

        $item = $this->makeStatamicItem();
        $attributes = [
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'payment' => '30.00',
            'total' => '90.00',
            'payment_gateway' => 'offline',
        ];

        $checkout = Reservation::factory([...$attributes, 'payment_id' => 'offline_abc', 'payment_surcharge' => '2.50'])->withCustomer()->create();

        $html = (new ReservationConfirmed($checkout))->render();

        // What the customer must transfer: the payment plus the gateway's surcharge.
        $this->assertMatchesRegularExpression('/Amount due<\/td>\s*<td[^>]*>[^<]*32\.50/', $html);
        $this->assertStringNotContainsString('Amount already paid', $html);
        $this->assertStringContainsString("Bank: ACME &lt;b&gt;Ltd&lt;/b&gt;<br>\nΔικαιούχος: Ευχαριστούμε ΑΕ<br>\nIBAN: GR12 3456", $html);
        $this->assertStringContainsString('Please include the booking reference '.$checkout->reference.' with your payment.', $html);

        // Marked as paid in the CP: the money arrived, so no payment details.
        $paidInCp = Reservation::factory([...$attributes, 'payment_id' => '', 'created_by' => 'admin-id'])->withCustomer()->create();

        $html = (new ReservationConfirmed($paidInCp))->render();

        $this->assertStringContainsString('Amount already paid', $html);
        $this->assertStringNotContainsString('IBAN: GR12 3456', $html);
    }
}
