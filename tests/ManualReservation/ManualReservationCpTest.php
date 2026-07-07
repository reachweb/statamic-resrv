<?php

namespace Reach\StatamicResrv\Tests\ManualReservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\CheckoutFormResolver;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Support\Str;

class ManualReservationCpTest extends TestCase
{
    use CreatesEntries, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(today()->setHour(12));
        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        // Validation/authorization tests assert HTTP status codes, so exceptions must
        // render instead of bubbling out of the test kernel.
        $this->withExceptionHandling();
    }

    private function useMultipleGateways(): void
    {
        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Fake Online',
            ],
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
    }

    private function configurePaymentEntry(): Entry
    {
        $entry = Entry::make()
            ->collection($this->findOrCreateCollection('pages'))
            ->slug('pay-here')
            ->data(['title' => 'Pay here']);
        $entry->save();

        Config::set('resrv-config.manual_reservations_payment_entry', [$entry->id()]);

        return $entry;
    }

    private function storePayload($entry, array $overrides = []): array
    {
        return array_merge([
            'item_id' => $entry->id(),
            'date_start' => today()->addDay()->setTime(12, 0)->toDateTimeString(),
            'date_end' => today()->addDays(3)->setTime(12, 0)->toDateTimeString(),
            'quantity' => 1,
            'rate_id' => Rate::forEntry($entry->id())->first()?->id,
            'payment_mode' => 'full',
            'payment_gateway' => 'offline',
            'customer' => [
                'email' => 'jane@example.com',
                'repeat_email' => 'jane@example.com',
                'first_name' => 'Jane',
                'last_name' => 'Doe',
            ],
        ], $overrides);
    }

    public function test_entries_endpoint_lists_enabled_resrv_entries()
    {
        $this->signInAdmin();
        $entry = $this->makeStatamicItemWithAvailability();

        $this->getJson(cp_route('resrv.manual.entries'))
            ->assertOk()
            ->assertJsonFragment([
                'item_id' => $entry->id(),
                'collection' => 'pages',
            ]);
    }

    public function test_entry_endpoint_returns_rates_and_form_fields()
    {
        $this->signInAdmin();
        $entry = $this->makeStatamicItemWithAvailability();
        $rate = Rate::forEntry($entry->id())->first();

        $response = $this->getJson(cp_route('resrv.manual.entry', ['item_id' => $entry->id()]))
            ->assertOk()
            ->assertJsonFragment(['id' => $rate->id, 'title' => $rate->title]);

        $handles = collect($response->json('form_fields'))->pluck('handle');
        $this->assertTrue($handles->contains('email'));
        $this->assertTrue($handles->contains('first_name'));
    }

    public function test_quote_endpoint_returns_pricing_and_available_extras_and_options()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $extra = Extra::factory()->create();
        ResrvEntry::whereItemId($entry->id())->extras()->attach($extra->id);

        $option = Option::factory()
            ->notRequired()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->create(['item_id' => $entry->id()]);

        $response = $this->postJson(cp_route('resrv.manual.quote'), [
            'item_id' => $entry->id(),
            'date_start' => today()->addDay()->setTime(12, 0)->toDateTimeString(),
            'date_end' => today()->addDays(3)->setTime(12, 0)->toDateTimeString(),
            'quantity' => 1,
            'rate_id' => Rate::forEntry($entry->id())->first()?->id,
            'payment_mode' => 'full',
            'extras' => [['id' => $extra->id, 'quantity' => 1]],
        ])->assertOk();

        $response->assertJsonPath('pricing.base_price', '100.00')
            ->assertJsonPath('pricing.extras_total', '9.30')
            ->assertJsonPath('pricing.total', '109.30')
            ->assertJsonPath('payment.amount', '109.30')
            ->assertJsonPath('availability.status', true);

        $this->assertEquals($extra->id, $response->json('available_extras.0.id'));
        $this->assertEquals('9.30', $response->json('available_extras.0.price'));
        $this->assertEquals($option->id, $response->json('available_options.0.id'));
        $this->assertNotEmpty($response->json('available_options.0.values'));
        $this->assertArrayHasKey('fake', $response->json('payment.gateways'));
    }

    public function test_quote_endpoint_validates_shape()
    {
        $this->signInAdmin();
        $entry = $this->makeStatamicItemWithAvailability();

        $this->postJson(cp_route('resrv.manual.quote'), [
            'item_id' => $entry->id(),
            'date_start' => today()->addDays(3)->toDateTimeString(),
            'date_end' => today()->addDay()->toDateTimeString(),
            'quantity' => 1,
            'payment_mode' => 'full',
        ])->assertStatus(422)->assertJsonValidationErrors(['date_end']);

        $this->postJson(cp_route('resrv.manual.quote'), [
            'item_id' => $entry->id(),
            'date_start' => today()->addDay()->toDateTimeString(),
            'date_end' => today()->addDays(3)->toDateTimeString(),
            'quantity' => 1,
            'payment_mode' => 'weird',
        ])->assertStatus(422)->assertJsonValidationErrors(['payment_mode']);
    }

    public function test_quote_endpoint_tolerates_custom_mode_without_an_amount_yet()
    {
        $this->signInAdmin();
        $entry = $this->makeStatamicItemWithAvailability();

        // Selecting "custom" fires a quote before the amount is typed; it must not blank
        // the quote (which would hide the very input the user needs) — unlike the store,
        // which still requires the amount at submit.
        $this->postJson(cp_route('resrv.manual.quote'), [
            'item_id' => $entry->id(),
            'date_start' => today()->addDay()->setTime(12, 0)->toDateTimeString(),
            'date_end' => today()->addDays(3)->setTime(12, 0)->toDateTimeString(),
            'quantity' => 1,
            'rate_id' => Rate::forEntry($entry->id())->first()?->id,
            'payment_mode' => 'custom',
            'custom_amount' => null,
        ])->assertOk()
            ->assertJsonPath('payment.mode', 'custom')
            ->assertJsonPath('payment.amount', '0.00');

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, ['payment_mode' => 'custom']))
            ->assertStatus(422)->assertJsonValidationErrors(['custom_amount']);
    }

    public function test_create_page_exposes_gateways_and_configuration_state()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();

        $this->get(cp_route('resrv.reservations.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('resrv::Reservations/Create')
                ->where('paymentEntryConfigured', false)
                ->where('gateways.0.key', 'fake')
                ->where('gateways.0.supports_manual_confirmation', false)
                ->where('gateways.1.key', 'offline')
                ->where('gateways.1.supports_manual_confirmation', true)
                ->whereType('affiliates', 'array'));

        $this->configurePaymentEntry();

        $this->get(cp_route('resrv.reservations.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('paymentEntryConfigured', true));
    }

    public function test_create_page_hides_affiliates_when_the_feature_is_off()
    {
        $this->signInAdmin();
        Config::set('resrv-config.enable_affiliates', false);
        Affiliate::factory()->create();

        $this->get(cp_route('resrv.reservations.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('affiliates', null));
    }

    public function test_store_creates_an_awaiting_payment_reservation()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $response = $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry))
            ->assertStatus(201);

        $reservation = Reservation::latest('id')->first();

        $this->assertEquals(
            cp_route('resrv.reservation.show', $reservation->id),
            $response->json('redirect')
        );
        $this->assertSame('awaiting_payment', $reservation->status);
        $this->assertSame('offline', $reservation->payment_gateway);
        $this->assertSame('100.00', $reservation->total->format());
        $this->assertSame('100.00', $reservation->payment->format());
        $this->assertSame('jane@example.com', $reservation->customer->email);
    }

    public function test_store_validation_matrix()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        // Missing item_id.
        $this->postJson(cp_route('resrv.manual.store'), collect($this->storePayload($entry))->except('item_id')->all())
            ->assertStatus(422)->assertJsonValidationErrors(['item_id']);

        // Unknown gateway.
        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, ['payment_gateway' => 'paypal']))
            ->assertStatus(422)->assertJsonValidationErrors(['payment_gateway']);

        // Custom mode without an amount.
        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, ['payment_mode' => 'custom']))
            ->assertStatus(422)->assertJsonValidationErrors(['custom_amount']);

        // Hold days below 1.
        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, ['hold_days' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors(['hold_days']);

        // Missing customer email.
        $payload = $this->storePayload($entry);
        unset($payload['customer']['email']);
        $this->postJson(cp_route('resrv.manual.store'), $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['customer.email']);

        // Checkout-form rule enforcement: first_name is required by the resolved form.
        $payload = $this->storePayload($entry);
        unset($payload['customer']['first_name']);
        $this->postJson(cp_route('resrv.manual.store'), $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['customer.first_name']);
    }

    public function test_customer_email_stays_required_when_the_checkout_form_marks_it_optional()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        // A custom checkout form whose email field is merely optional must not weaken the manual
        // reservation's explicit required|email — an online awaiting reservation needs an email
        // for its payment URL and request recipient. The checkout-form rules are merged before
        // the explicit ones so the explicit rule wins.
        $field = \Mockery::mock();
        $field->shouldReceive('handle')->andReturn('email');
        $field->shouldReceive('config')->andReturn(['validate' => ['email']]);

        $fields = \Mockery::mock();
        $fields->shouldReceive('values')->andReturn(collect([$field]));

        $form = \Mockery::mock(FormContract::class);
        $form->shouldReceive('fields')->andReturn($fields);

        $resolver = \Mockery::mock(CheckoutFormResolver::class);
        $resolver->shouldReceive('resolveForEntryId')->andReturn($form);
        $this->app->instance(CheckoutFormResolver::class, $resolver);

        $payload = $this->storePayload($entry);
        unset($payload['customer']['email']);

        $this->postJson(cp_route('resrv.manual.store'), $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['customer.email']);
    }

    public function test_store_rejects_online_gateways_when_the_payment_entry_is_unconfigured()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, ['payment_gateway' => 'fake']))
            ->assertStatus(422);

        $this->configurePaymentEntry();

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, ['payment_gateway' => 'fake']))
            ->assertStatus(201);
    }

    public function test_store_rejects_amounts_outside_the_gateway_limits()
    {
        $this->signInAdmin();
        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Fake Online',
                'amount_limits' => ['min' => 500],
            ],
        ]);
        $this->configurePaymentEntry();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, ['payment_gateway' => 'fake']))
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'The requested amount is outside the allowed limits for this payment method.']);
    }

    public function test_store_maps_availability_exceptions_to_422()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 0);

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry))
            ->assertStatus(422);

        $this->assertDatabaseCount('resrv_reservations', 0);
    }

    public function test_all_endpoints_are_forbidden_without_the_use_resrv_permission()
    {
        $this->withExceptionHandling();

        $role = Role::make('role_'.Str::random(8))->addPermission(['access cp'])->save();
        $user = User::make()
            ->id('user-'.Str::random(8))
            ->email(Str::random(8).'@test.com')
            ->assignRole($role);
        $this->actingAs($user);

        $this->getJson(cp_route('resrv.manual.entries'))->assertForbidden();
        $this->getJson(cp_route('resrv.manual.entry', ['item_id' => 'x']))->assertForbidden();
        $this->postJson(cp_route('resrv.manual.quote'), [])->assertForbidden();
        $this->postJson(cp_route('resrv.manual.store'), [])->assertForbidden();
    }
}
