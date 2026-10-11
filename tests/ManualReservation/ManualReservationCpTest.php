<?php

namespace Reach\StatamicResrv\Tests\ManualReservation;

use Illuminate\Cache\RetrievesMultipleKeys;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Mail\ReservationConfirmed as ReservationConfirmedMail;
use Reach\StatamicResrv\Mail\ReservationMade;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\ExtraCondition;
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

        // Assertions target HTTP status codes, so exceptions must render.
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
                // A human title for the picker's badge, not the handle.
                'collection_title' => 'Pages',
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
        $this->assertSame($entry->editUrl(), $response->json('edit_url'));
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
        $publishedValue = $option->values->first();
        $unpublishedValue = OptionValue::factory()->fixed()->create([
            'option_id' => $option->id,
            'published' => false,
        ]);

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

        // Only published values are offered — a draft value must not appear in the picker.
        $valueIds = collect($response->json('available_options.0.values'))->pluck('id');
        $this->assertTrue($valueIds->contains($publishedValue->id));
        $this->assertFalse($valueIds->contains($unpublishedValue->id));
        $this->assertArrayHasKey('fake', $response->json('payment.gateways'));
    }

    public function test_quote_endpoint_flags_gateways_that_cannot_accept_the_amount()
    {
        $this->signInAdmin();
        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Fake Online',
                'amount_limits' => ['min' => 500],
            ],
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Bank Transfer',
            ],
        ]);
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        // Total is 100.00 — below fake's 500 minimum, so the form must disable it.
        $this->postJson(cp_route('resrv.manual.quote'), [
            'item_id' => $entry->id(),
            'date_start' => today()->addDay()->setTime(12, 0)->toDateTimeString(),
            'date_end' => today()->addDays(3)->setTime(12, 0)->toDateTimeString(),
            'quantity' => 1,
            'rate_id' => Rate::forEntry($entry->id())->first()?->id,
            'payment_mode' => 'full',
        ])->assertOk()
            ->assertJsonPath('payment.gateways.fake.available', false)
            ->assertJsonPath('payment.gateways.offline.available', true);
    }

    public function test_quote_endpoint_lists_extras_coupon_free_when_the_admin_session_carries_a_coupon()
    {
        $this->signInAdmin();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $extra = Extra::factory()->create();
        ResrvEntry::whereItemId($entry->id())->extras()->attach($extra->id);

        // Coupon-gated 20% decrease on the EXTRA that engages only while session('resrv_coupon') matches.
        $dynamic = DynamicPricing::factory()->withCoupon()->create();
        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $extra->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Extra',
        ]);
        Cache::forget('dynamic_pricing_table');
        Cache::forget('dynamic_pricing_assignments_table');

        $priceData = [
            'item_id' => $entry->id(),
            'date_start' => today()->addDay()->setTime(12, 0)->toDateTimeString(),
            'date_end' => today()->addDays(3)->setTime(12, 0)->toDateTimeString(),
            'quantity' => 1,
        ];

        // Sanity: the raw extras listing DOES discount, so the endpoint's coupon-free scope must be what blocks it.
        session(['resrv_coupon' => '20OFF']);
        $this->assertSame('7.44', Extra::getPriceForDates($priceData)->first()->price->format());

        $response = $this->withSession(['resrv_coupon' => '20OFF'])
            ->postJson(cp_route('resrv.manual.quote'), array_merge($priceData, [
                'rate_id' => Rate::forEntry($entry->id())->first()?->id,
                'payment_mode' => 'full',
                'extras' => [['id' => $extra->id, 'quantity' => 1]],
            ]))->assertOk();

        // The listed per-unit price and the quoted totals must agree — all coupon-free.
        $response->assertJsonPath('pricing.extras_total', '9.30')
            ->assertJsonPath('available_extras.0.price', '9.30');

        // The admin's own in-progress frontend checkout keeps its coupon.
        $response->assertSessionHas('resrv_coupon', '20OFF');
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

    public function test_quote_endpoint_rejects_unparseable_numeric_amounts_with_422()
    {
        $this->signInAdmin();
        $entry = $this->makeStatamicItemWithAvailability();

        $base = [
            'item_id' => $entry->id(),
            'date_start' => today()->addDay()->setTime(12, 0)->toDateTimeString(),
            'date_end' => today()->addDays(3)->setTime(12, 0)->toDateTimeString(),
            'quantity' => 1,
            'rate_id' => Rate::forEntry($entry->id())->first()?->id,
        ];

        // Scientific notation passes Laravel's `numeric` rule but not moneyphp's parser — must 422, never 500.
        $this->postJson(cp_route('resrv.manual.quote'), array_merge($base, [
            'payment_mode' => 'full',
            'total_override' => '1e3',
        ]))->assertStatus(422)->assertJson(['error' => __('The overridden total is not a valid amount.')]);

        // A rejected custom amount keeps the quote pricing and reports the reason for the field.
        $this->postJson(cp_route('resrv.manual.quote'), array_merge($base, [
            'payment_mode' => 'custom',
            'custom_amount' => '1e3',
        ]))->assertOk()
            ->assertJsonPath('payment.amount', '0.00')
            ->assertJsonPath('payment.custom_amount_error', __('The custom amount is not a valid amount.'));
    }

    public function test_quote_endpoint_keeps_pricing_when_the_total_drops_under_the_custom_amount()
    {
        $this->signInAdmin();
        $entry = $this->makeStatamicItemWithAvailability();

        $payload = [
            'item_id' => $entry->id(),
            'date_start' => today()->addDay()->setTime(12, 0)->toDateTimeString(),
            'date_end' => today()->addDays(3)->setTime(12, 0)->toDateTimeString(),
            'quantity' => 1,
            'rate_id' => Rate::forEntry($entry->id())->first()?->id,
            'payment_mode' => 'custom',
            'custom_amount' => '400',
        ];

        $this->postJson(cp_route('resrv.manual.quote'), array_merge($payload, ['total_override' => '500']))
            ->assertOk()
            ->assertJsonPath('payment.amount', '400.00')
            ->assertJsonPath('payment.custom_amount_error', null);

        // Lowering the override under the amount must refresh the total the form judges the
        // amount against — a 422 would leave the old 500 total on screen with no field error.
        $this->postJson(cp_route('resrv.manual.quote'), array_merge($payload, ['total_override' => '300']))
            ->assertOk()
            ->assertJsonPath('pricing.total', '300.00')
            ->assertJsonPath('payment.amount', '0.00')
            ->assertJsonPath('payment.custom_amount_error', __('The custom amount cannot exceed the reservation total.'));

        // Creation still rejects it.
        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, [
            'payment_mode' => 'custom',
            'custom_amount' => '400',
            'total_override' => '300',
            'payment_gateway' => 'fake',
        ]))->assertStatus(422)->assertJson(['error' => __('The custom amount cannot exceed the reservation total.')]);
    }

    public function test_quote_endpoint_tolerates_custom_mode_without_an_amount_yet()
    {
        $this->signInAdmin();
        $entry = $this->makeStatamicItemWithAvailability();

        // Selecting "custom" fires a quote before the amount is typed; it must not blank the quote (the store still requires it).
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

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, ['payment_mode' => 'custom', 'payment_gateway' => 'fake']))
            ->assertStatus(422)->assertJson(['error' => __('A custom amount is required for the custom payment mode.')]);
    }

    public function test_store_allows_omitted_custom_amount_when_the_total_is_overridden_to_zero()
    {
        $this->signInAdmin();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        // A zero override comps the booking: custom mode must accept the omitted amount the
        // creator treats as a legitimate comped booking instead of rejecting it up front.
        $response = $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, [
            'payment_mode' => 'custom',
            'payment_gateway' => null,
            'total_override' => '0',
        ]))->assertStatus(201);

        $reservation = Reservation::find($response->json('id'));
        $this->assertSame('confirmed', $reservation->status);
        $this->assertSame('0.00', $reservation->payment->format());
        $this->assertSame('0.00', $reservation->total->format());
    }

    public function test_quote_endpoint_prices_custom_extras_with_the_customer_data()
    {
        $this->signInAdmin();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        // price 10, price_type 'custom', driven by the customer form field 'adults'.
        $extra = Extra::factory()->custom()->create();
        ResrvEntry::whereItemId($entry->id())->extras()->attach($extra->id);

        $payload = [
            'item_id' => $entry->id(),
            'date_start' => today()->addDay()->setTime(12, 0)->toDateTimeString(),
            'date_end' => today()->addDays(3)->setTime(12, 0)->toDateTimeString(),
            'quantity' => 1,
            'rate_id' => Rate::forEntry($entry->id())->first()?->id,
            'payment_mode' => 'full',
            'extras' => [['id' => $extra->id, 'quantity' => 1]],
        ];

        // The quote and the listed per-unit price must carry the same multiplier creation will charge (10 × 3 adults), not the ×1 fallback.
        $this->postJson(cp_route('resrv.manual.quote'), array_merge($payload, [
            'customer' => ['email' => 'jane@example.com', 'adults' => 3],
        ]))->assertOk()
            ->assertJsonPath('pricing.extras_total', '30.00')
            ->assertJsonPath('pricing.total', '130.00')
            ->assertJsonPath('available_extras.0.price', '30.00');

        // A selected custom extra with an empty driving field must fail the quote, as creation would.
        $this->postJson(cp_route('resrv.manual.quote'), array_merge($payload, [
            'customer' => ['email' => '', 'adults' => ''],
        ]))->assertStatus(422)
            ->assertJson(['error' => __('The extra ":name" could not be priced — check its custom-price field on the customer form.', ['name' => $extra->name])]);
    }

    public function test_quote_endpoint_lists_custom_extras_gracefully_while_the_driving_field_is_empty()
    {
        $this->signInAdmin();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $extra = Extra::factory()->custom()->create();
        ResrvEntry::whereItemId($entry->id())->extras()->attach($extra->id);

        // An unselected custom extra with an empty driving field lists at the ×1 base price instead of throwing
        // (Extra::getCustomPrice throws on a present-but-unusable customer payload).
        $this->postJson(cp_route('resrv.manual.quote'), [
            'item_id' => $entry->id(),
            'date_start' => today()->addDay()->setTime(12, 0)->toDateTimeString(),
            'date_end' => today()->addDays(3)->setTime(12, 0)->toDateTimeString(),
            'quantity' => 1,
            'rate_id' => Rate::forEntry($entry->id())->first()?->id,
            'payment_mode' => 'full',
            'customer' => ['email' => 'jane@example.com', 'adults' => ''],
        ])->assertOk()
            ->assertJsonPath('available_extras.0.price', '10.00')
            ->assertJsonPath('pricing.total', '100.00');
    }

    public function test_entry_endpoint_resolves_dictionary_field_items()
    {
        $this->signInAdmin();
        $entry = $this->makeStatamicItemWithAvailability();

        // A scratch blueprint directory keeps the addon's shipped checkout blueprint untouched.
        $directory = sys_get_temp_dir().'/resrv-blueprints-'.uniqid();
        mkdir($directory.'/forms', 0755, true);
        file_put_contents($directory.'/forms/checkout.yaml', <<<'YAML'
        tabs:
          main:
            sections:
              -
                fields:
                  -
                    handle: email
                    field:
                      type: text
                      display: Email
                  -
                    handle: country
                    field:
                      type: dictionary
                      display: Country
                      dictionary: countries
                  -
                    handle: dialing_code
                    field:
                      type: dictionary
                      display: 'Dialing code'
                      dictionary: country_phone_codes
        YAML);
        Blueprint::setDirectory($directory);

        $fields = collect(
            $this->getJson(cp_route('resrv.manual.entry', ['item_id' => $entry->id()]))
                ->assertOk()
                ->json('form_fields')
        )->keyBy('handle');

        // A plain dictionary carries resolved items so the CP form offers the same choices as checkout.
        $country = $fields->get('country');
        $this->assertFalse($country['phone_dictionary']);
        $this->assertNotEmpty($country['dictionary_items']);
        $this->assertArrayHasKey('value', $country['dictionary_items'][0]);
        $this->assertArrayHasKey('label', $country['dictionary_items'][0]);

        // The phone variant renders as a free-typed tel input on the CP — flagged, no items.
        $dialingCode = $fields->get('dialing_code');
        $this->assertTrue($dialingCode['phone_dictionary']);
        $this->assertSame([], $dialingCode['dictionary_items']);
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
                ->where('gateways.0.supports_webhooks', true)
                ->where('gateways.0.description', __('Customer pays online through the payment link; the reservation confirms automatically.'))
                ->where('gateways.1.key', 'offline')
                ->where('gateways.1.supports_manual_confirmation', true)
                ->where('gateways.1.supports_webhooks', false)
                ->where('gateways.1.description', __('Customer pays outside Resrv (e.g. bank transfer); you mark the reservation as paid.'))
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

        // Custom mode without an amount on a nonzero total (the creator enforces this — the
        // request rule stays nullable so zero-total comped bookings can omit it).
        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, ['payment_mode' => 'custom']))
            ->assertStatus(422)->assertJson(['error' => __('A custom amount is required for the custom payment mode.')]);

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

        // An optional checkout-form email must not weaken the explicit required|email — the payment link needs an address.
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

    public function test_dependent_checkout_rules_follow_the_fields_into_the_customer_namespace()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        // On the checkout, sibling fields are referenced as form.<handle>; the rule must be
        // re-pointed to customer.* here or it matches nothing and silently passes.
        $country = \Mockery::mock();
        $country->shouldReceive('handle')->andReturn('country');
        $country->shouldReceive('config')->andReturn(['validate' => ['required']]);

        $state = \Mockery::mock();
        $state->shouldReceive('handle')->andReturn('state');
        $state->shouldReceive('config')->andReturn(['validate' => ['required_if:form.country,US']]);

        $fields = \Mockery::mock();
        $fields->shouldReceive('values')->andReturn(collect([$country, $state]));

        $form = \Mockery::mock(FormContract::class);
        $form->shouldReceive('fields')->andReturn($fields);

        $resolver = \Mockery::mock(CheckoutFormResolver::class);
        $resolver->shouldReceive('resolveForEntryId')->andReturn($form);
        $resolver->shouldReceive('resolveForReservation')->andReturn($form);
        $this->app->instance(CheckoutFormResolver::class, $resolver);

        $payload = $this->storePayload($entry, ['send_payment_request_email' => false]);
        $payload['customer'] = ['email' => 'jane@example.com', 'country' => 'US'];

        $this->postJson(cp_route('resrv.manual.store'), $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['customer.state']);

        $payload['customer'] = ['email' => 'jane@example.com', 'country' => 'GR'];

        $this->postJson(cp_route('resrv.manual.store'), $payload)
            ->assertStatus(201);
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

    private function quotePayload($entry, array $overrides = []): array
    {
        return array_merge([
            'item_id' => $entry->id(),
            'date_start' => today()->addDay()->setTime(12, 0)->toDateTimeString(),
            'date_end' => today()->addDays(3)->setTime(12, 0)->toDateTimeString(),
            'quantity' => 1,
            'rate_id' => Rate::forEntry($entry->id())->first()?->id,
            'payment_mode' => 'standard',
        ], $overrides);
    }

    public function test_entries_are_sorted_by_collection_title_then_entry_title()
    {
        $this->signInAdmin();
        $room = $this->makeStatamicItemWithAvailability(collection: 'rooms', title: 'A Room');
        $page = $this->makeStatamicItemWithAvailability(title: 'Z Page');

        $ids = collect($this->getJson(cp_route('resrv.manual.entries'))->assertOk()->json())->pluck('item_id')->all();

        // "Pages" sorts before "Rooms" even though the room's own title comes first.
        $this->assertSame([$page->id(), $room->id()], $ids);
    }

    public function test_quote_endpoint_reports_the_computed_figures_behind_an_override()
    {
        $this->signInAdmin();
        Config::set('resrv-config.payment', 'percent');
        Config::set('resrv-config.percent_amount', 20);
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $this->postJson(cp_route('resrv.manual.quote'), $this->quotePayload($entry, ['total_override' => '70']))
            ->assertOk()
            ->assertJsonPath('pricing.computed_base_price', '100.00')
            ->assertJsonPath('pricing.computed_total', '100.00')
            ->assertJsonPath('pricing.adjustment', '-30.00')
            ->assertJsonPath('pricing.total', '70.00')
            ->assertJsonPath('pricing.discount', null)
            // Standard = 20% of the (overridden) base; the rest is not collected by Resrv.
            ->assertJsonPath('payment.amount', '14.00')
            ->assertJsonPath('pricing.balance', '56.00');

        // Without an override the computed figures equal the charged ones and nothing is adjusted.
        $this->postJson(cp_route('resrv.manual.quote'), $this->quotePayload($entry))
            ->assertOk()
            ->assertJsonPath('pricing.computed_total', '100.00')
            ->assertJsonPath('pricing.total', '100.00')
            ->assertJsonPath('pricing.adjustment', '0.00');
    }

    public function test_quote_endpoint_prices_both_payment_modes_whichever_is_selected()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        Config::set('resrv-config.payment', 'percent');
        Config::set('resrv-config.percent_amount', 20);
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        foreach (['standard', 'full'] as $mode) {
            $this->postJson(cp_route('resrv.manual.quote'), $this->quotePayload($entry, ['payment_mode' => $mode]))
                ->assertOk()
                ->assertJsonPath('payment.standard_amount', '20.00')
                ->assertJsonPath('payment.full_amount', '100.00')
                ->assertJsonPath('payment.gateways.offline.surcharge_is_zero', true);
        }

        $this->postJson(cp_route('resrv.manual.quote'), $this->quotePayload($entry, ['payment_mode' => 'custom', 'custom_amount' => '35']))
            ->assertOk()
            ->assertJsonPath('payment.amount', '35.00')
            ->assertJsonPath('payment.standard_amount', '20.00')
            ->assertJsonPath('pricing.balance', '65.00');
    }

    public function test_quote_endpoint_reports_a_dynamic_pricing_discount()
    {
        $this->signInAdmin();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        // The factory rule takes 20% off reservations in range.
        $dynamic = DynamicPricing::factory()->create([
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(10)->toIso8601String(),
            'condition_value' => '1',
        ]);
        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $entry->id(),
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);
        Cache::forget('dynamic_pricing_table');
        Cache::forget('dynamic_pricing_assignments_table');

        $response = $this->postJson(cp_route('resrv.manual.quote'), $this->quotePayload($entry))->assertOk();

        $original = $response->json('pricing.original_base_price');
        $computed = $response->json('pricing.computed_base_price');
        $this->assertNotNull($original);
        $this->assertNotSame($original, $computed);
        $this->assertSame(
            number_format((float) $original - (float) $computed, 2, '.', ''),
            $response->json('pricing.discount')
        );
    }

    public function test_quote_validation_errors_carry_admin_wording_under_their_field()
    {
        $this->signInAdmin();
        $entry = $this->makeStatamicItemWithAvailability();

        $sameDay = today()->addDay()->setTime(12, 0)->toDateTimeString();
        $this->postJson(cp_route('resrv.manual.quote'), $this->quotePayload($entry, ['date_start' => $sameDay, 'date_end' => $sameDay]))
            ->assertStatus(422)
            ->assertJsonPath('errors.date_end.0', __('The end date must be after the start date.'));

        $this->postJson(cp_route('resrv.manual.quote'), $this->quotePayload($entry, [
            'date_start' => today()->subDay()->toDateTimeString(),
            'date_end' => today()->addDay()->toDateTimeString(),
        ]))->assertStatus(422)
            ->assertJsonPath('errors.date_start.0', __('The start date is in the past.'));

        $this->postJson(cp_route('resrv.manual.quote'), $this->quotePayload($entry, ['quantity' => 9]))
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', 'Maximum quantity per reservation is 8.');

        $this->postJson(cp_route('resrv.manual.quote'), $this->quotePayload($entry, ['quantity' => 0]))
            ->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', __('Quantity must be at least 1.'));

        // A booking starting today is not "in the past".
        $this->postJson(cp_route('resrv.manual.quote'), $this->quotePayload($entry, [
            'date_start' => today()->toDateTimeString(),
            'date_end' => today()->addDay()->toDateTimeString(),
        ]))->assertOk();
    }

    public function test_stay_length_limits_are_reported_in_admin_wording_under_the_dates()
    {
        $this->signInAdmin();
        $entry = $this->makeStatamicItemWithAvailability();

        Config::set('resrv-config.maximum_reservation_period_in_days', 1);

        $this->postJson(cp_route('resrv.manual.quote'), $this->quotePayload($entry))
            ->assertStatus(422)
            ->assertJsonPath('field', 'dates')
            ->assertJsonPath('error', __('The stay is longer than the maximum of :days nights.', ['days' => 1]));

        Config::set('resrv-config.maximum_reservation_period_in_days', 30);
        Config::set('resrv-config.minimum_reservation_period_in_days', 5);

        $this->postJson(cp_route('resrv.manual.quote'), $this->quotePayload($entry))
            ->assertStatus(422)
            ->assertJsonPath('field', 'dates')
            ->assertJsonPath('error', __('The stay is shorter than the minimum of :days nights.', ['days' => 5]));
    }

    public function test_every_customer_email_field_shares_one_validation_wording()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $payload = $this->storePayload($entry);
        $payload['customer']['email'] = 'not-an-email';
        $payload['customer']['repeat_email'] = 'not-an-email';

        $errors = $this->postJson(cp_route('resrv.manual.store'), $payload)->assertStatus(422)->json('errors');

        $this->assertSame(__('Enter a valid email address.'), $errors['customer.email'][0]);
        $this->assertSame(__('Enter a valid email address.'), $errors['customer.repeat_email'][0]);
    }

    public function test_quote_domain_errors_name_the_field_they_belong_to()
    {
        $this->signInAdmin();
        $entry = $this->makeStatamicItemWithAvailability();
        $extra = Extra::factory()->create();
        ResrvEntry::whereItemId($entry->id())->extras()->attach($extra->id);

        $this->postJson(cp_route('resrv.manual.quote'), $this->quotePayload($entry, [
            'extras' => [['id' => $extra->id, 'quantity' => 1]],
            'total_override' => '5',
        ]))->assertStatus(422)
            ->assertJsonPath('field', 'total_override');

        $unpublished = Rate::factory()->create(['collection' => 'pages', 'slug' => 'hidden', 'published' => false]);

        $this->postJson(cp_route('resrv.manual.quote'), $this->quotePayload($entry, ['rate_id' => $unpublished->id]))
            ->assertStatus(422)
            ->assertJsonPath('field', 'rate_id');
    }

    public function test_quote_flags_required_extras_and_store_names_the_missing_ones()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $extra = Extra::factory()->create(['name' => 'Insurance']);
        ResrvEntry::whereItemId($entry->id())->extras()->attach($extra->id);
        ExtraCondition::factory()->requiredAlways()->create(['extra_id' => $extra->id]);

        $this->postJson(cp_route('resrv.manual.quote'), $this->quotePayload($entry))
            ->assertOk()
            ->assertJsonPath('available_extras.0.id', $extra->id)
            ->assertJsonPath('available_extras.0.required', true)
            ->assertJsonPath('available_extras.0.required_reason', __('Always required for this entry.'));

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry))
            ->assertStatus(422)
            ->assertJsonPath('field', 'extras')
            ->assertJsonPath('error', __('Add the required extras: :extras.', ['extras' => 'Insurance']));

        $this->assertDatabaseCount('resrv_reservations', 0);

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, [
            'extras' => [['id' => $extra->id, 'quantity' => 1]],
        ]))->assertStatus(201);
    }

    public function test_store_names_the_required_options_left_unselected()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        Option::factory()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->create(['item_id' => $entry->id(), 'name' => 'Table location', 'required' => true]);

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry))
            ->assertStatus(422)
            ->assertJsonPath('field', 'options')
            ->assertJsonPath('error', __('Select a value for: :options.', ['options' => 'Table location']));
    }

    public function test_store_reports_the_resulting_status_and_whether_the_request_was_emailed()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry))
            ->assertStatus(201)
            ->assertJsonPath('status', 'awaiting_payment')
            ->assertJsonPath('payment_request_sent', true);

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, ['send_payment_request_email' => false]))
            ->assertStatus(201)
            ->assertJsonPath('payment_request_sent', false);
    }

    public function test_a_repeated_store_with_the_same_idempotency_key_returns_the_first_reservation()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $payload = $this->storePayload($entry, ['idempotency_key' => 'form-session-1', 'affects_availability' => true]);

        $first = $this->postJson(cp_route('resrv.manual.store'), $payload)->assertStatus(201)->assertJsonPath('replayed', false);
        $retry = $this->postJson(cp_route('resrv.manual.store'), $payload)->assertStatus(200)->assertJsonPath('replayed', true);

        $this->assertSame($first->json('id'), $retry->json('id'));
        $this->assertSame($first->json('redirect'), $retry->json('redirect'));
        $this->assertDatabaseCount('resrv_reservations', 1);

        // A new form session (new key) is a new booking.
        $this->postJson(cp_route('resrv.manual.store'), array_merge($payload, ['idempotency_key' => 'form-session-2']))
            ->assertStatus(201);
        $this->assertDatabaseCount('resrv_reservations', 2);
    }

    public function test_an_edited_retry_with_the_same_idempotency_key_is_refused_with_a_link_to_the_first_reservation()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $payload = $this->storePayload($entry, ['idempotency_key' => 'form-session-edit']);
        $first = $this->postJson(cp_route('resrv.manual.store'), $payload)->assertStatus(201);

        // The admin fixed a typo after a failed-looking submit; the same form session must not
        // report the first booking as if the corrected one had been created.
        $edited = $payload;
        $edited['customer']['email'] = 'jane@example.org';
        $edited['customer']['repeat_email'] = 'jane@example.org';

        $this->postJson(cp_route('resrv.manual.store'), $edited)
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_created')
            ->assertJsonPath('redirect', $first->json('redirect'))
            ->assertJsonPath('error', __('A reservation was already created from this form (#:id). Open it to review before creating another.', ['id' => $first->json('id')]));

        $this->assertDatabaseCount('resrv_reservations', 1);

        // Key order does not make an identical retry look edited.
        $reordered = array_reverse($payload, true);
        $reordered['customer'] = array_reverse($payload['customer'], true);

        $this->postJson(cp_route('resrv.manual.store'), $reordered)
            ->assertStatus(200)
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('id', $first->json('id'));
    }

    public function test_a_retry_after_a_failure_past_the_commit_replays_instead_of_booking_twice()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        // A zero-amount booking commits, then its confirmation write fails once (e.g. a lock-wait error) → 500.
        $failOnce = true;
        Reservation::updating(function (Reservation $reservation) use (&$failOnce) {
            if ($failOnce && $reservation->isDirty('status') && $reservation->status === 'confirmed') {
                $failOnce = false;

                throw new \RuntimeException('Lock wait timeout exceeded');
            }
        });

        $payload = $this->storePayload($entry, ['idempotency_key' => 'post-commit-failure', 'total_override' => '0']);

        $this->postJson(cp_route('resrv.manual.store'), $payload)->assertStatus(500);
        $this->assertDatabaseCount('resrv_reservations', 1);

        $this->postJson(cp_route('resrv.manual.store'), $payload)
            ->assertStatus(200)
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('id', Reservation::first()->id);

        $this->assertDatabaseCount('resrv_reservations', 1);
    }

    public function test_idempotent_creation_works_on_a_cache_store_without_locks()
    {
        $store = new class extends TaggableStore
        {
            use RetrievesMultipleKeys;

            private array $items = [];

            public function get($key)
            {
                return $this->items[$key] ?? null;
            }

            public function put($key, $value, $seconds)
            {
                $this->items[$key] = $value;

                return true;
            }

            public function increment($key, $value = 1)
            {
                return $this->items[$key] = ($this->items[$key] ?? 0) + $value;
            }

            public function decrement($key, $value = 1)
            {
                return $this->increment($key, -$value);
            }

            public function forever($key, $value)
            {
                return $this->put($key, $value, 0);
            }

            public function touch($key, $seconds)
            {
                return array_key_exists($key, $this->items);
            }

            public function forget($key)
            {
                unset($this->items[$key]);

                return true;
            }

            public function flush()
            {
                $this->items = [];

                return true;
            }

            public function getPrefix()
            {
                return '';
            }
        };

        Cache::extend('resrv-lockless', fn () => Cache::repository($store));
        Config::set('cache.stores.resrv-lockless', ['driver' => 'resrv-lockless']);
        Cache::setDefaultDriver('resrv-lockless');
        $this->assertFalse(Cache::getStore() instanceof LockProvider);

        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $payload = $this->storePayload($entry, ['idempotency_key' => 'lockless-store']);

        $first = $this->postJson(cp_route('resrv.manual.store'), $payload)->assertStatus(201);
        $this->postJson(cp_route('resrv.manual.store'), $payload)
            ->assertStatus(200)
            ->assertJsonPath('id', $first->json('id'));

        $this->assertDatabaseCount('resrv_reservations', 1);
    }

    public function test_the_idempotency_key_is_scoped_to_the_admin()
    {
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);
        $payload = $this->storePayload($entry, ['idempotency_key' => 'shared-key']);

        $this->signInAdmin();
        $this->postJson(cp_route('resrv.manual.store'), $payload)->assertStatus(201);

        $this->be(User::make()->id('second-admin')->email('second@test.com')->makeSuper());
        $this->postJson(cp_route('resrv.manual.store'), $payload)->assertStatus(201);

        $this->assertDatabaseCount('resrv_reservations', 2);
    }

    public function test_a_failed_store_does_not_consume_the_idempotency_key()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, [
            'idempotency_key' => 'retry-after-fix',
            'payment_mode' => 'custom',
        ]))->assertStatus(422);

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, [
            'idempotency_key' => 'retry-after-fix',
        ]))->assertStatus(201);
    }

    public function test_store_refuses_figures_that_changed_since_the_quote()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        // The admin was shown 90.00, but the night prices have since moved to 100.00.
        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, [
            'expected_total' => '90.00',
            'expected_amount' => '90.00',
        ]))->assertStatus(422)
            ->assertJsonPath('code', 'price_changed')
            ->assertJsonPath('error', __('The price changed since it was last shown — review the updated figures and create again.'));

        $this->assertDatabaseCount('resrv_reservations', 0);

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, [
            'expected_total' => '100.00',
            'expected_amount' => '100.00',
        ]))->assertStatus(201);
    }

    public function test_a_zero_amount_store_records_neither_gateway_nor_hold()
    {
        $this->signInAdmin();
        $this->useMultipleGateways();
        $this->configurePaymentEntry();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $response = $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, [
            'total_override' => '0',
            'payment_gateway' => 'fake',
            'hold_days' => 3,
        ]))->assertStatus(201)
            ->assertJsonPath('status', 'confirmed');

        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $response->json('id'),
            'status' => 'confirmed',
            'payment_gateway' => '',
            'hold_expires_at' => null,
        ]);
    }

    public function test_the_email_switch_governs_the_customer_confirmation_of_a_zero_amount_booking()
    {
        Mail::fake();
        Config::set('resrv-config.admin_email', 'admin@example.com');
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, [
            'total_override' => '0',
            'send_payment_request_email' => false,
        ]))->assertStatus(201);

        Mail::assertNotSent(ReservationConfirmedMail::class);
        Mail::assertSent(ReservationMade::class, fn ($mail) => $mail->hasTo('admin@example.com'));

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, [
            'total_override' => '0',
        ]))->assertStatus(201);

        Mail::assertSent(ReservationConfirmedMail::class, fn ($mail) => $mail->hasTo('jane@example.com'));
    }

    public function test_hold_days_are_bounded_and_required_when_the_hold_switch_is_sent_on()
    {
        $this->freezeSecond();
        $this->signInAdmin();
        $this->useMultipleGateways();
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, ['hold_days' => 366]))
            ->assertStatus(422)->assertJsonValidationErrors(['hold_days']);

        foreach ([null, ''] as $blank) {
            $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, ['hold_enabled' => true, 'hold_days' => $blank]))
                ->assertStatus(422)
                ->assertJsonPath('errors.hold_days.0', __('Enter the number of days to hold the reservation.'));
        }

        $this->assertDatabaseCount('resrv_reservations', 0);

        // Without the switch (or with it off) a blank count still means "no hold".
        $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, ['hold_enabled' => false, 'hold_days' => null]))
            ->assertStatus(201);

        $response = $this->postJson(cp_route('resrv.manual.store'), $this->storePayload($entry, ['hold_enabled' => true, 'hold_days' => 2]))
            ->assertStatus(201);

        $this->assertTrue(Reservation::find($response->json('id'))->hold_expires_at->equalTo(now()->addDays(2)));
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
