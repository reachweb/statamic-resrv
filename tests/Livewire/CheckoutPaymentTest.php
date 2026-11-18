<?php

namespace Reach\StatamicResrv\Tests\Livewire;

use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Livewire\CheckoutPayment;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;

class CheckoutPaymentTest extends TestCase
{
    use CreatesEntries;

    public $date;

    public $entries;

    public $reservation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = now()->add(1, 'day')->setTime(12, 0, 0);
        $this->entries = $this->createEntries();
        $this->travelTo(today()->setHour(12));
        $this->reservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
        ]);

        $entry = Entry::make()
            ->collection('pages')
            ->slug('checkout')
            ->data(['title' => 'Checkout']);

        $entry->save();

        Config::set('resrv-config.checkout_completed_entry', $entry->id());
    }

    public function test_renders_successfully()
    {
        Livewire::test(CheckoutPayment::class)
            ->assertViewIs('statamic-resrv::livewire.checkout-payment')
            ->assertViewHas('checkoutCompletedUrl', function ($checkoutCompletedUrl) {
                return $checkoutCompletedUrl === Entry::find(Config::get('resrv-config.checkout_completed_entry'))->absoluteUrl();
            });
    }

    public function test_payment_views_show_the_amount_with_the_currency_decimals()
    {
        // Checkout passes the amount as a float, which drops the trailing zero (316.8).
        Livewire::test(CheckoutPayment::class, ['amount' => 316.80])
            ->assertSeeHtml('€ 316.80</span>');

        Livewire::test(CheckoutPayment::class, ['amount' => 316.80, 'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline'])
            ->assertSeeHtml('€ 316.80</span>');

        // The yen has no minor unit: a fixed two-decimal format would print "¥ 3,168.00".
        Config::set('resrv-config.currency_isoCode', 'JPY');
        Config::set('resrv-config.currency_symbol', '¥');

        Livewire::test(CheckoutPayment::class, ['amount' => 3168.0])
            ->assertSeeHtml('¥ 3168</span>');

        Livewire::test(CheckoutPayment::class, ['amount' => 3168.0, 'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline'])
            ->assertSeeHtml('¥ 3168</span>');
    }

    public function test_offline_payment_step_shows_the_offline_instructions()
    {
        $offline = ['amount' => 100.0, 'paymentView' => 'statamic-resrv::livewire.checkout-payment-offline'];

        Livewire::test(CheckoutPayment::class, $offline)
            ->assertDontSee('IBAN');

        Config::set('resrv-config.manual_reservations_offline_instructions', "Bank: ACME <b>Ltd</b>\nIBAN: GR12 3456");

        Livewire::test(CheckoutPayment::class, $offline)
            ->assertSeeHtml("Bank: ACME &lt;b&gt;Ltd&lt;/b&gt;<br />\nIBAN: GR12 3456")
            ->assertDontSeeHtml('<b>Ltd</b>');
    }

    public function test_confirm_payment_on_non_pending_reservation_surfaces_error_without_500()
    {
        // A REFUNDED reservation cannot transition to CONFIRMED. confirmPayment() routes through
        // confirmOrAlreadyConfirmed() -> transitionTo(..., tolerant: true), so a non-confirmable
        // state must surface a friendly error rather than throwing InvalidStateTransition (HTTP 500).
        Config::set('resrv-config.payment_gateways', [
            'offline' => ['class' => OfflinePaymentGateway::class],
        ]);
        $this->app->forgetInstance(PaymentGatewayManager::class);

        $reservation = Reservation::factory()->create([
            'item_id' => $this->entries->first()->id(),
            'status' => 'refunded',
            'payment_gateway' => 'offline',
        ]);

        session(['resrv_reservation' => $reservation->id]);

        Livewire::test(CheckoutPayment::class)
            ->call('confirmPayment')
            ->assertHasErrors('reservation');

        $this->assertEquals('refunded', $reservation->fresh()->status);
    }
}
