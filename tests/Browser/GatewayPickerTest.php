<?php

namespace Reach\StatamicResrv\Tests\Browser;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Laravel\Dusk\Browser;
use Orchestra\Testbench\Dusk\Attributes\BeforeServing;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;

/**
 * The multi-gateway payment picker. With a single gateway the picker is auto-skipped
 * (covered by T14); this exercises the *multi*-gateway UI: with two gateways the picker
 * renders, selecting one initializes that gateway's payment and the payment table
 * reflects the choice (its surcharge). The second gateway is registered in the SERVED
 * app via beforeServingApplication (Gotcha #7) — a real entry in payment_gateways the
 * browser process can see, not a test-process Config::set the served app would miss.
 *
 * Also asserts one representative checkout error path rendering — attempting checkout
 * without choosing a rate on a multi-rate results page → "Please select a rate".
 */
class GatewayPickerTest extends BrowserTestCase
{
    #[BeforeServing('registerSecondGateway')]
    public function test_gateway_picker_renders_two_gateways_and_selection_reflects_in_payment_table(): void
    {
        $this->browse(function (Browser $browser) {
            $this->reachPaymentStep($browser);

            // Two gateways → the picker renders both; no payment UI or surcharge yet.
            $browser->assertPresent('@gateway-offline')
                ->assertPresent('@gateway-offline_express')
                ->assertMissing('@confirm-payment')
                ->assertMissing('@payment-surcharge');

            // Selecting the surcharged gateway initializes its (offline) payment UI and
            // the payment table now shows the surcharge line.
            $browser->click('@gateway-offline_express')
                ->waitFor('@confirm-payment', 10)
                ->waitFor('@payment-surcharge')
                ->assertVisible('@payment-surcharge');
        });
    }

    public function test_checkout_without_a_rate_renders_the_select_a_rate_error(): void
    {
        // Dates within the seeded window; only need to pass the search's date rules so
        // availability loads for both rates (count > 1 trips the guard).
        $start = today()->addDays(5)->toDateString();
        $end = today()->addDays(7)->toDateString();

        $this->browse(function (Browser $browser) use ($start, $end) {
            $browser->visit('/__t/results-multi')->waitFor('[dusk=results-multi-route]');

            // Load availability for the two-rate entry with rate='any'. The advanced rate
            // selector renders (no Book Now button), so the guard in checkout() is only
            // reachable by calling it directly — the same window.Livewire poke T13/T15 use.
            $browser->script(<<<JS
                const root = document.querySelector('[dusk=results-multi-route] [wire\\\\:id]');
                window.Livewire.find(root.getAttribute('wire:id')).call('availabilitySearchChanged', { dates: { date_start: '$start', date_end: '$end' }, quantity: 1, rate: 'any' });
            JS);

            $browser->waitFor('[wire\\:click^="checkoutRate"]');

            $browser->script(<<<'JS'
                const root = document.querySelector('[dusk=results-multi-route] [wire\\:id]');
                window.Livewire.find(root.getAttribute('wire:id')).call('checkout');
            JS);

            $browser->waitForText('Please select a rate before proceeding.')
                ->assertSee('Please select a rate before proceeding.');
        });
    }

    /**
     * Register a second, offline-style gateway (with a surcharge) in the SERVED app.
     * Resolved by testbench-dusk's #[BeforeServing] attribute and applied through the
     * config repository before the WorkbenchServiceProvider registers — which leaves a
     * deliberate 2-gateway override in place instead of forcing offline-only. Public and
     * stateless: the served process invokes it on a fresh test instance.
     */
    public function registerSecondGateway(Application $app, Repository $config): void
    {
        $config->set('resrv-config.payment_gateways', [
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Pay on arrival',
            ],
            'offline_express' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Express checkout',
                'surcharge' => ['type' => 'percent', 'amount' => 10],
            ],
        ]);
    }

    /**
     * Drive the standard funnel to checkout step 3 (payment): search → Book Now → step 1
     * → handleFirstStep → fill the customer form → submit. With two gateways step 3 lands
     * on the picker rather than auto-selecting.
     */
    private function reachPaymentStep(Browser $browser): void
    {
        $browser->visit('/bookable')->waitFor('[name=datepicker]')
            ->click('[name=datepicker]')->waitFor('.rc-day__label')
            ->click('.rc-day--available:not(.rc-day--hidden)')->waitFor('[wire\\:click="checkout()"]')
            ->click('[wire\\:click="checkout()"]')
            ->waitForLocation('/checkout')
            ->waitFor('[wire\\:click="handleFirstStep()"]')
            ->click('[wire\\:click="handleFirstStep()"]')
            ->waitFor('#first_name', 10)
            ->type('#first_name', 'Jane')
            ->type('#last_name', 'Doe')
            ->type('#email', 'jane@example.com')
            ->type('#repeat_email', 'jane@example.com')
            ->click('[wire\\:click="submit()"]')
            ->waitFor('@gateway-offline', 10);
    }
}
