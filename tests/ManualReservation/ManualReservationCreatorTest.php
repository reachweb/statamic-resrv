<?php

namespace Reach\StatamicResrv\Tests\ManualReservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Reach\StatamicResrv\Events\ReservationConfirmed as ReservationConfirmedEvent;
use Reach\StatamicResrv\Exceptions\AvailabilityException;
use Reach\StatamicResrv\Exceptions\ManualReservationException;
use Reach\StatamicResrv\Exceptions\OptionsException;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\OfflinePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Livewire\Checkout;
use Reach\StatamicResrv\Mail\ReservationConfirmed as ReservationConfirmedMail;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\ExtraCategory;
use Reach\StatamicResrv\Models\ExtraCondition;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ManualReservationCreator;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\Support\WebhooklessOnlineGateway;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint;
use Statamic\Facades\User;

class ManualReservationCreatorTest extends TestCase
{
    use CreatesEntries, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(today()->setHour(12));

        Blueprint::setDirectory(__DIR__.'/../../resources/blueprints');

        $checkoutEntry = Entry::make()->collection($this->findOrCreateCollection('pages'))->slug('checkout')->data(['title' => 'Checkout']);
        $checkoutEntry->save();
        Config::set('resrv-config.checkout_entry', $checkoutEntry->id());
        Config::set('resrv-config.checkout_completed_entry', $checkoutEntry->id());

        // Nonzero creations validate against the collecting gateway, so the payment page must resolve.
        $this->configurePaymentEntry();
    }

    private function configurePaymentEntry(): void
    {
        if (config('resrv-config.manual_reservations_payment_entry')) {
            return;
        }

        $entry = Entry::make()
            ->collection($this->findOrCreateCollection('pages'))
            ->slug('pay-here')
            ->data(['title' => 'Pay here']);
        $entry->save();

        Config::set('resrv-config.manual_reservations_payment_entry', [$entry->id()]);
    }

    private function creator(): ManualReservationCreator
    {
        return app(ManualReservationCreator::class);
    }

    private function dates(): array
    {
        return [
            'date_start' => today()->addDay()->setTime(12, 0)->toDateTimeString(),
            'date_end' => today()->addDays(3)->setTime(12, 0)->toDateTimeString(),
        ];
    }

    private function baseInput($entry, array $overrides = []): array
    {
        return array_merge($this->dates(), [
            'item_id' => $entry->id(),
            'quantity' => 1,
            'rate_id' => Rate::forEntry($entry->id())->first()?->id,
            'payment_mode' => 'standard',
            'customer' => [
                'email' => 'customer@example.com',
                'first_name' => 'Test',
                'last_name' => 'Customer',
                'repeat_email' => 'customer@example.com',
            ],
        ], $overrides);
    }

    private function attachExtraTo($entry): Extra
    {
        // The Extra factory hard-codes id 1, so reuse the row across entries.
        $extra = Extra::first() ?? Extra::factory()->create();
        ResrvEntry::whereItemId($entry->id())->extras()->attach($extra->id);

        return $extra;
    }

    /**
     * Runs the real frontend checkout flow for the same booking (AvailabilityResults-style
     * reservation + Livewire first step) and returns the amount checkout would charge.
     */
    private function frontendCheckoutPayment($entry, array $extrasPayload = [], int $quantity = 1): string
    {
        $rateId = Rate::forEntry($entry->id())->first()?->id;
        $data = array_merge($this->dates(), ['quantity' => $quantity, 'rate_id' => $rateId]);

        $result = (new Availability)->getAvailabilityForEntry($data, $entry->id());
        $cancellation = Rate::effectiveCancellationPolicyFor($rateId);

        $reservation = Reservation::factory()->create([
            'item_id' => $entry->id(),
            'date_start' => $data['date_start'],
            'date_end' => $data['date_end'],
            'quantity' => $quantity,
            'rate_id' => $rateId,
            'price' => $result['data']['price'],
            'payment' => $result['data']['payment'],
            'cancellation_policy' => $cancellation['policy']->value,
            'free_cancellation_period' => $cancellation['period'],
        ]);

        session(['resrv_reservation' => $reservation->id]);

        $component = Livewire::test(Checkout::class);

        if (! empty($extrasPayload)) {
            $component->dispatch('extras-updated', $extrasPayload);
        }

        $component->call('handleFirstStep')->assertSet('step', 2);

        return Reservation::find($reservation->id)->payment->format();
    }

    private function availableOn(string $itemId, $date): int
    {
        return (int) Availability::where('statamic_id', $itemId)
            ->where('date', '>=', $date->toDateString())
            ->where('date', '<', $date->copy()->addDay()->toDateString())
            ->first()
            ->available;
    }

    public function test_standard_amount_matches_a_real_checkout_for_every_payment_config()
    {
        foreach ([
            ['payment' => 'full'],
            ['payment' => 'fixed', 'fixed_amount' => 30],
            ['payment' => 'percent', 'percent_amount' => 20],
            ['payment' => 'everything'],
        ] as $config) {
            foreach ($config as $key => $value) {
                Config::set("resrv-config.{$key}", $value);
            }

            $entry = $this->makeStatamicItemWithAvailability(available: 4);
            $extra = $this->attachExtraTo($entry);
            $extraPrice = Extra::find($extra->id)->priceForDates(array_merge($this->dates(), [
                'quantity' => 1,
                'item_id' => $entry->id(),
            ]));

            $frontendAmount = $this->frontendCheckoutPayment($entry, [
                $extra->id => ['id' => $extra->id, 'quantity' => 1, 'price' => $extraPrice, 'name' => $extra->name],
            ]);

            $quote = $this->creator()->quote($this->baseInput($entry, [
                'extras' => [['id' => $extra->id, 'quantity' => 1]],
            ]));

            $this->assertSame(
                $frontendAmount,
                $quote['payment']['amount']->format(),
                "Standard amount drifted from the real checkout for payment config [{$config['payment']}]."
            );
        }
    }

    public function test_standard_amount_forces_the_full_total_for_a_non_refundable_rate()
    {
        Config::set('resrv-config.payment', 'fixed');
        Config::set('resrv-config.fixed_amount', 30);

        $rate = Rate::factory()->nonRefundable()->create(['collection' => 'pages', 'slug' => 'non-refundable']);
        $entry = $this->makeStatamicItemWithAvailability(available: 4, rateId: $rate->id);
        $extra = $this->attachExtraTo($entry);
        $extraPrice = Extra::find($extra->id)->priceForDates(array_merge($this->dates(), [
            'quantity' => 1,
            'item_id' => $entry->id(),
        ]));

        $frontendAmount = $this->frontendCheckoutPayment($entry, [
            $extra->id => ['id' => $extra->id, 'quantity' => 1, 'price' => $extraPrice, 'name' => $extra->name],
        ]);

        $quote = $this->creator()->quote($this->baseInput($entry, [
            'extras' => [['id' => $extra->id, 'quantity' => 1]],
        ]));

        $this->assertSame($frontendAmount, $quote['payment']['amount']->format());
        $this->assertSame($quote['pricing']['total']->format(), $quote['payment']['amount']->format());
    }

    public function test_override_back_computes_the_base_price()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);
        $extra = $this->attachExtraTo($entry);

        $quote = $this->creator()->quote($this->baseInput($entry, [
            'extras' => [['id' => $extra->id, 'quantity' => 1]],
            'total_override' => '150.00',
        ]));

        $this->assertSame('150.00', $quote['pricing']['total']->format());
        $this->assertSame('140.70', $quote['pricing']['base_price']->format());
        $this->assertSame('9.30', $quote['pricing']['extras_total']->format());
        $this->assertTrue($quote['pricing']['total_overridden']);
    }

    public function test_override_lower_than_the_charges_is_rejected()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);
        $extra = $this->attachExtraTo($entry);

        $this->expectException(ManualReservationException::class);

        $this->creator()->quote($this->baseInput($entry, [
            'extras' => [['id' => $extra->id, 'quantity' => 1]],
            'total_override' => '5.00',
        ]));
    }

    public function test_override_skips_dynamic_pricing_records_while_normal_creation_keeps_them()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        $dynamic = DynamicPricing::factory()->create([
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->add(10, 'day')->toIso8601String(),
            'condition_value' => '1',
        ]);

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $entry->id(),
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        Cache::forget('dynamic_pricing_table');
        Cache::forget('dynamic_pricing_assignments_table');

        $overridden = $this->creator()->create($this->baseInput($entry, ['total_override' => '90.00']));

        $this->assertDatabaseMissing('resrv_reservation_dynamic_pricing', ['reservation_id' => $overridden->id]);

        $normal = $this->creator()->create($this->baseInput($entry));

        $this->assertDatabaseHas('resrv_reservation_dynamic_pricing', [
            'reservation_id' => $normal->id,
            'dynamic_pricing_id' => $dynamic->id,
        ]);
    }

    public function test_custom_amount_bounds()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        foreach (['0', '999.00'] as $invalid) {
            try {
                $this->creator()->quote($this->baseInput($entry, [
                    'payment_mode' => 'custom',
                    'custom_amount' => $invalid,
                ]));
                $this->fail("Custom amount [{$invalid}] should have been rejected.");
            } catch (ManualReservationException $e) {
                $this->addToAssertionCount(1);
            }
        }

        $reservation = $this->creator()->create($this->baseInput($entry, [
            'payment_mode' => 'custom',
            'custom_amount' => '35.00',
        ]));

        $this->assertSame('35.00', $reservation->payment->format());
    }

    public function test_custom_amount_is_validated_even_when_the_total_is_overridden_to_zero()
    {
        Mail::fake();
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        // Regression: the zero-total shortcut in requestedAmount() skipped customAmount(), confirming a free booking instead of rejecting the contradiction.
        $input = $this->baseInput($entry, [
            'payment_mode' => 'custom',
            'custom_amount' => '35.00',
            'total_override' => '0',
        ]);

        try {
            $this->creator()->create($input);
            $this->fail('A positive custom amount on a zero total should have been rejected.');
        } catch (ManualReservationException $e) {
            $this->addToAssertionCount(1);
        }

        // The live quote keeps pricing but reports the rejection for the field.
        $quote = $this->creator()->quote($input, requireCustomAmount: false);
        $this->assertTrue($quote['payment']['amount']->isZero());
        $this->assertSame(__('The custom amount cannot exceed the reservation total.'), $quote['payment']['custom_amount_error']);

        $this->assertSame(0, Reservation::count());

        // An omitted custom amount stays a legitimate fully-comped booking that confirms free.
        $reservation = $this->creator()->create($this->baseInput($entry, [
            'payment_mode' => 'custom',
            'total_override' => '0',
        ]));

        $this->assertSame('confirmed', $reservation->status);
        $this->assertTrue($reservation->payment->isZero());
    }

    public function test_creation_persists_every_snapshot_column()
    {
        Config::set('resrv-config.payment', 'fixed');
        Config::set('resrv-config.fixed_amount', 30);
        $this->configurePaymentEntry();

        $rate = Rate::factory()->freeCancellation(14)->create(['collection' => 'pages', 'slug' => 'flexible']);
        $entry = $this->makeStatamicItemWithAvailability(available: 4, rateId: $rate->id);
        $extra = $this->attachExtraTo($entry);
        $option = Option::factory()
            ->notRequired()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->create(['item_id' => $entry->id()]);
        $optionValue = $option->values->first();

        $creator = User::make()->id('admin-user-1')->email('admin@example.com');

        $reservation = $this->creator()->create($this->baseInput($entry, [
            'extras' => [['id' => $extra->id, 'quantity' => 2]],
            'options' => [['id' => $option->id, 'value' => $optionValue->id]],
            'payment_gateway' => 'fake',
            'hold_days' => 3,
        ]), $creator);

        $this->assertSame('awaiting_payment', $reservation->status);
        $this->assertSame('normal', $reservation->type);
        $this->assertSame(6, strlen($reservation->reference));
        $this->assertSame($entry->id(), $reservation->item_id);
        $this->assertEquals($rate->id, $reservation->rate_id);
        $this->assertSame('free_cancellation', $reservation->cancellation_policy);
        $this->assertSame(14, $reservation->free_cancellation_period);
        $this->assertSame('fake', $reservation->payment_gateway);
        $this->assertSame('', $reservation->payment_id);
        $this->assertSame('admin-user-1', $reservation->created_by);
        $this->assertTrue($reservation->affects_availability);
        $this->assertSame(now()->addDays(3)->toDateTimeString(), $reservation->hold_expires_at->toDateTimeString());

        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => $reservation->id,
            'extra_id' => $extra->id,
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('resrv_reservation_option', [
            'reservation_id' => $reservation->id,
            'option_id' => $option->id,
            'value' => $optionValue->id,
        ]);

        $extrasTotal = Extra::find($extra->id)->calculatePrice(array_merge($this->dates(), [
            'quantity' => 1,
            'rate_id' => $rate->id,
            'item_id' => $entry->id(),
        ]), 2);
        $optionTotal = $option->calculatePrice(array_merge($this->dates(), [
            'quantity' => 1,
            'rate_id' => $rate->id,
            'item_id' => $entry->id(),
        ]), $optionValue->id);
        $expectedTotal = Price::create('100.00')->add($extrasTotal, $optionTotal);

        $this->assertTrue($reservation->total->equals($expectedTotal));
        $this->assertSame('30.00', $reservation->payment->format());
    }

    public function test_customer_row_filters_unknown_form_handles()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        $reservation = $this->creator()->create($this->baseInput($entry, [
            'customer' => [
                'email' => 'jane@example.com',
                'first_name' => 'Jane',
                'evil_key' => 'injected',
            ],
        ]));

        $customer = $reservation->customer;
        $this->assertSame('jane@example.com', $customer->email);
        $this->assertSame('Jane', $customer->data->get('first_name'));
        $this->assertFalse($customer->data->has('evil_key'));
    }

    public function test_affiliate_is_attached_with_its_fee_snapshot()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);
        $affiliate = Affiliate::factory()->create(['fee' => 12]);

        $reservation = $this->creator()->create($this->baseInput($entry, [
            'affiliate_id' => $affiliate->id,
        ]));

        $this->assertDatabaseHas('resrv_reservation_affiliate', [
            'reservation_id' => $reservation->id,
            'affiliate_id' => $affiliate->id,
            'fee' => 12,
        ]);
    }

    public function test_an_unpublished_affiliate_is_rejected()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        // A stale or crafted payload must not attach an affiliate the UI hides.
        $affiliate = Affiliate::factory()->create(['published' => false]);

        $this->expectException(ManualReservationException::class);
        $this->expectExceptionMessage('affiliate was not found');

        $this->creator()->create($this->baseInput($entry, [
            'affiliate_id' => $affiliate->id,
        ]));
    }

    public function test_an_affiliate_is_rejected_when_the_feature_is_disabled()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);
        $affiliate = Affiliate::factory()->create();

        Config::set('resrv-config.enable_affiliates', false);

        $this->expectException(ManualReservationException::class);
        $this->expectExceptionMessage('Affiliates are disabled');

        $this->creator()->create($this->baseInput($entry, [
            'affiliate_id' => $affiliate->id,
        ]));
    }

    public function test_stock_is_decremented_only_when_the_flag_is_on()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 2);

        $this->creator()->create($this->baseInput($entry));
        $this->assertEquals(1, $this->availableOn($entry->id(), today()->addDay()));

        $entryUntouched = $this->makeStatamicItemWithAvailability(available: 2);

        $this->creator()->create($this->baseInput($entryUntouched, ['affects_availability' => false]));
        $this->assertEquals(2, $this->availableOn($entryUntouched->id(), today()->addDay()));
    }

    public function test_overbooking_is_allowed_only_when_the_flag_is_off()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 0);

        $reservation = $this->creator()->create($this->baseInput($entry, ['affects_availability' => false]));
        $this->assertSame('awaiting_payment', $reservation->status);
        $this->assertEquals(0, $this->availableOn($entry->id(), today()->addDay()));

        $entryBlocked = $this->makeStatamicItemWithAvailability(available: 0);
        $before = Reservation::count();

        try {
            $this->creator()->create($this->baseInput($entryBlocked));
            $this->fail('Creating with affects_availability=true and no stock should throw.');
        } catch (AvailabilityException $e) {
            $this->assertEquals($before, Reservation::count());
        }
    }

    public function test_overbooking_cannot_bypass_an_unpublished_rate()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 0);
        Rate::forEntry($entry->id())->first()->update(['published' => false]);

        $before = Reservation::count();

        // The overbook toggle bypasses stock only — an unpublished rate must still be rejected.
        try {
            $this->creator()->create($this->baseInput($entry, ['affects_availability' => false]));
            $this->fail('An unpublished rate must not be bookable through the overbook toggle.');
        } catch (ManualReservationException $e) {
            $this->assertStringContainsString('not available for this entry', $e->getMessage());
            $this->assertEquals($before, Reservation::count());
        }
    }

    public function test_a_missing_rate_id_resolves_to_the_entrys_published_rate()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 2);
        $rate = Rate::forEntry($entry->id())->first();

        $quote = $this->creator()->quote($this->baseInput($entry, ['rate_id' => null]));
        $this->assertEquals($rate->id, $quote['rate_id']);

        $reservation = $this->creator()->create($this->baseInput($entry, ['rate_id' => null]));

        // The resolved rate is stored so the stock decrement matches real rows (a null rate_id matches none).
        $this->assertEquals($rate->id, $reservation->rate_id);
        $this->assertEquals(1, $this->availableOn($entry->id(), today()->addDay()));
    }

    public function test_a_missing_rate_id_prices_against_the_resolved_rate_only()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 2, price: 50);

        // A rate-blind pricing read (null rate_id applies no rate filter) would fold this pricier unpublished rate's rows into the base price.
        $unpublished = Rate::factory()->create([
            'slug' => 'unpublished-rate',
            'title' => 'Unpublished',
            'published' => false,
            'order' => 2,
        ]);
        Availability::factory()
            ->count(4)
            ->sequence(
                ['date' => today()],
                ['date' => today()->addDay()],
                ['date' => today()->addDays(2)],
                ['date' => today()->addDays(3)],
            )
            ->create([
                'statamic_id' => $entry->id(),
                'rate_id' => $unpublished->id,
                'available' => 5,
                'price' => '999',
            ]);

        $quote = $this->creator()->quote($this->baseInput($entry, ['rate_id' => null]));

        $this->assertSame('100.00', $quote['pricing']['base_price']->format());
    }

    public function test_a_missing_rate_id_cannot_bypass_an_unpublished_rate()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 0);
        Rate::forEntry($entry->id())->first()->update(['published' => false]);

        $before = Reservation::count();

        // An omitted rate_id must resolve-and-validate a published rate, not skip rate validation.
        try {
            $this->creator()->create($this->baseInput($entry, ['rate_id' => null, 'affects_availability' => false]));
            $this->fail('Omitting the rate id must not bypass rate validation for the overbook toggle.');
        } catch (ManualReservationException $e) {
            $this->assertStringContainsString('no published rate', $e->getMessage());
            $this->assertEquals($before, Reservation::count());
        }
    }

    public function test_overbooking_cannot_bypass_rate_stay_and_lead_time_restrictions()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);
        Rate::forEntry($entry->id())->first()->update(['min_stay' => 5]);

        $before = Reservation::count();

        // baseInput books 2 nights: the min-stay rule blocks it — the overbook toggle is not a rate-rule override.
        try {
            $this->creator()->create($this->baseInput($entry, ['affects_availability' => false]));
            $this->fail('A stay-restriction violation must not be bookable through the overbook toggle.');
        } catch (ManualReservationException $e) {
            $this->assertStringContainsString('does not allow these dates', $e->getMessage());
            $this->assertEquals($before, Reservation::count());
        }
    }

    public function test_quote_reports_the_remaining_stock_and_overbook_flag()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 1);

        $quote = $this->creator()->quote($this->baseInput($entry, ['quantity' => 2]));

        $this->assertFalse($quote['availability']['status']);
        $this->assertTrue($quote['availability']['overbook']);
        $this->assertEquals(1, $quote['availability']['available']);
    }

    public function test_missing_required_option_throws()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);
        Option::factory()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->create(['item_id' => $entry->id(), 'required' => true]);

        $this->expectException(OptionsException::class);

        $this->creator()->create($this->baseInput($entry));
    }

    public function test_zero_total_confirms_immediately_and_sends_the_confirmation_email()
    {
        Mail::fake();

        $entry = $this->makeStatamicItemWithAvailability(available: 4, price: 0);

        $reservation = $this->creator()->create($this->baseInput($entry));

        $this->assertSame('confirmed', $reservation->status);
        $this->assertTrue($reservation->total->isZero());
        $this->assertTrue($reservation->payment->isZero());

        // The confirmation email job is dispatched afterResponse; outside HTTP it runs on app termination.
        $this->app->terminate();
        Mail::assertSent(ReservationConfirmedMail::class, fn ($mail) => $mail->hasTo('customer@example.com'));
    }

    public function test_gateway_surcharge_is_computed_on_the_requested_amount()
    {
        $this->configurePaymentEntry();
        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Fake',
                'surcharge' => ['type' => 'percent', 'amount' => 10],
            ],
            'offline' => [
                'class' => OfflinePaymentGateway::class,
                'label' => 'Offline',
            ],
        ]);

        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        $reservation = $this->creator()->create($this->baseInput($entry, [
            'payment_mode' => 'full',
            'payment_gateway' => 'fake',
        ]));

        $this->assertSame('100.00', $reservation->payment->format());
        $this->assertSame('10.00', $reservation->payment_surcharge->format());

        $quote = $this->creator()->quote($this->baseInput($entry, [
            'payment_mode' => 'full',
            'payment_gateway' => 'offline',
        ]));

        $this->assertTrue($quote['payment']['surcharge']->isZero());
        $this->assertSame('10.00', $quote['payment']['gateways']['fake']['surcharge']->format());
        $this->assertTrue($quote['payment']['gateways']['offline']['surcharge']->isZero());
    }

    public function test_a_zero_requested_amount_on_a_nonzero_total_confirms_immediately()
    {
        Mail::fake();

        // A zero deposit on a positive total must confirm immediately (checkout keys off the amount collected now), gateway or not.
        Config::set('resrv-config.payment', 'fixed');
        Config::set('resrv-config.fixed_amount', 0);
        Config::set('resrv-config.full_payment_after_free_cancellation', false);

        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        $reservation = $this->creator()->create($this->baseInput($entry, [
            'payment_gateway' => 'fake',
        ]));

        $this->assertSame('confirmed', $reservation->status);
        $this->assertFalse($reservation->total->isZero());
        $this->assertTrue($reservation->payment->isZero());
    }

    public function test_a_zero_amount_booking_records_neither_the_sent_gateway_nor_a_hold()
    {
        Mail::fake();

        $comped = $this->creator()->create($this->baseInput($this->makeStatamicItemWithAvailability(available: 4), [
            'total_override' => '0',
            'payment_gateway' => 'fake',
            'hold_days' => 3,
        ]));

        // A zero deposit on a nonzero total collects nothing now either.
        Config::set('resrv-config.payment', 'fixed');
        Config::set('resrv-config.fixed_amount', 0);

        $zeroDeposit = $this->creator()->create($this->baseInput($this->makeStatamicItemWithAvailability(available: 4), [
            'payment_gateway' => 'fake',
            'hold_days' => 3,
        ]));

        // Confirmed at once and collected through nothing: like checkout's zero-payment path,
        // no gateway is recorded and no hold deadline is set.
        foreach ([$comped, $zeroDeposit] as $reservation) {
            $this->assertSame('confirmed', $reservation->status);
            $this->assertSame('', $reservation->payment_gateway);
            $this->assertNull($reservation->hold_expires_at);
        }

        $this->assertFalse($zeroDeposit->total->isZero());
    }

    public function test_standard_amount_matches_checkout_when_free_cancellation_has_passed_and_for_several_units()
    {
        // Checkout's rule: full payment is forced when the free-cancellation window is already
        // closed at booking time (check-in tomorrow, 14-day window).
        Config::set('resrv-config.payment', 'percent');
        Config::set('resrv-config.percent_amount', 20);
        Config::set('resrv-config.full_payment_after_free_cancellation', true);

        $rate = Rate::factory()->freeCancellation(14)->create(['collection' => 'pages']);
        $entry = $this->makeStatamicItemWithAvailability(available: 4, rateId: $rate->id);

        $frontendAmount = $this->frontendCheckoutPayment($entry);
        $quote = $this->creator()->quote($this->baseInput($entry));

        $this->assertSame($frontendAmount, $quote['payment']['amount']->format());
        $this->assertSame($quote['pricing']['total']->format(), $quote['payment']['amount']->format());

        // Several units with an extra: the base and the extra both scale with the quantity.
        Config::set('resrv-config.full_payment_after_free_cancellation', false);
        $entry = $this->makeStatamicItemWithAvailability(collection: 'rooms', available: 4);
        $extra = $this->attachExtraTo($entry);
        $extraPrice = Extra::find($extra->id)->priceForDates(array_merge($this->dates(), [
            'quantity' => 2,
            'item_id' => $entry->id(),
        ]));

        $frontendAmount = $this->frontendCheckoutPayment($entry, [
            $extra->id => ['id' => $extra->id, 'quantity' => 1, 'price' => $extraPrice, 'name' => $extra->name],
        ], quantity: 2);

        $quote = $this->creator()->quote($this->baseInput($entry, [
            'quantity' => 2,
            'extras' => [['id' => $extra->id, 'quantity' => 1]],
        ]));

        $this->assertSame($frontendAmount, $quote['payment']['amount']->format());
    }

    public function test_the_standard_amount_never_exceeds_an_overridden_total()
    {
        Config::set('resrv-config.payment', 'fixed');
        Config::set('resrv-config.fixed_amount', 50);

        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        $quote = $this->creator()->quote($this->baseInput($entry, ['total_override' => '20.00']));

        $this->assertSame('20.00', $quote['payment']['standard_amount']->format());
        $this->assertSame('20.00', $quote['payment']['amount']->format());

        $reservation = $this->creator()->create($this->baseInput($entry, ['total_override' => '20.00']));

        $this->assertSame('20.00', $reservation->payment->format());
        $this->assertSame('0.00', $reservation->amountRemaining());
    }

    public function test_the_quote_prices_every_payment_mode_and_the_computed_figures()
    {
        Config::set('resrv-config.payment', 'percent');
        Config::set('resrv-config.percent_amount', 20);

        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        $quote = $this->creator()->quote($this->baseInput($entry, [
            'payment_mode' => 'custom',
            'custom_amount' => '30.00',
            'total_override' => '80.00',
        ]));

        $this->assertSame('30.00', $quote['payment']['amount']->format());
        $this->assertSame('16.00', $quote['payment']['standard_amount']->format());
        $this->assertSame('80.00', $quote['payment']['full_amount']->format());
        $this->assertSame('100.00', $quote['pricing']['computed_base_price']->format());
        $this->assertSame('100.00', $quote['pricing']['computed_total']->format());
        $this->assertSame('-20.00', $quote['pricing']['adjustment']->format());
        $this->assertSame('50.00', $quote['pricing']['balance']->format());
        $this->assertNull($quote['pricing']['discount']);

        $zero = $this->creator()->quote($this->baseInput($entry, ['total_override' => '0']));
        $this->assertTrue($zero['payment']['standard_amount']->isZero());
    }

    public function test_overbooking_still_requires_a_price_for_every_night()
    {
        // Rows exist (no stock) from today to today+3: a stay running past them is partly unpriced.
        $entry = $this->makeStatamicItemWithAvailability(available: 0);

        $input = $this->baseInput($entry, [
            'date_start' => today()->addDays(2)->setTime(12, 0)->toDateTimeString(),
            'date_end' => today()->addDays(6)->setTime(12, 0)->toDateTimeString(),
            'affects_availability' => false,
        ]);

        foreach (['quote', 'create'] as $method) {
            try {
                $this->creator()->{$method}($input);
                $this->fail("{$method}() must refuse a stay with unpriced nights instead of undercharging it.");
            } catch (ManualReservationException $e) {
                $this->assertSame('dates', $e->field);
                $this->assertSame(__('These dates have no price configured for every night of the stay.'), $e->getMessage());
            }
        }

        $this->assertSame(0, Reservation::count());

        // A fully priced stay is still overbookable.
        $reservation = $this->creator()->create($this->baseInput($entry, ['affects_availability' => false]));
        $this->assertSame('100.00', $reservation->total->format());
    }

    public function test_the_creation_rolls_back_when_the_stock_is_taken_between_the_quote_and_the_decrement()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 1);
        $extra = $this->attachExtraTo($entry);

        // Another booking takes the last unit after the quote passed but before the
        // in-transaction ReservationCreated listeners decrement it.
        Reservation::created(function () use ($entry) {
            Availability::where('statamic_id', $entry->id())->update(['available' => 0]);
        });

        try {
            $this->creator()->create($this->baseInput($entry, [
                'extras' => [['id' => $extra->id, 'quantity' => 1]],
                'affects_availability' => true,
            ]));
            $this->fail('The decrement must fail once the stock is gone.');
        } catch (AvailabilityException) {
            // The reservation, its customer and its add-on rows all roll back with the failed decrement.
            $this->assertSame(0, Reservation::count());
            $this->assertSame(0, DB::table('resrv_customers')->count());
            $this->assertSame(0, DB::table('resrv_reservation_extra')->count());
        }
    }

    public function test_creation_refuses_figures_that_differ_from_what_the_admin_was_shown()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        foreach (['expected_total', 'expected_amount'] as $key) {
            try {
                $this->creator()->create($this->baseInput($entry, ['payment_mode' => 'full', $key => '99.00']));
                $this->fail("A mismatching {$key} must be refused.");
            } catch (ManualReservationException $e) {
                $this->assertSame('price_changed', $e->reason);
            }
        }

        $this->assertSame(0, Reservation::count());

        $reservation = $this->creator()->create($this->baseInput($entry, [
            'payment_mode' => 'full',
            'expected_total' => '100.00',
            'expected_amount' => '100',
        ]));
        $this->assertSame('100.00', $reservation->total->format());
    }

    public function test_required_extras_carry_the_reason_they_are_required()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        $trigger = Extra::factory()->create(['name' => 'Bike rental']);
        $helmet = Extra::factory()->create(['id' => 2, 'slug' => 'helmet', 'name' => 'Helmet']);
        ResrvEntry::whereItemId($entry->id())->extras()->attach([$trigger->id, $helmet->id]);
        ExtraCondition::factory()->create([
            'extra_id' => $helmet->id,
            'conditions' => [['operation' => 'required', 'type' => 'extra_selected', 'value' => $trigger->id]],
        ]);

        $this->assertTrue($this->creator()->requiredExtras($this->baseInput($entry))->isEmpty());

        $required = $this->creator()->requiredExtras($this->baseInput($entry, [
            'extras' => [['id' => $trigger->id, 'quantity' => 1]],
        ]));

        $this->assertSame([(int) $helmet->id], $required->keys()->all());
        $this->assertSame(__('Required because :extra is selected.', ['extra' => 'Bike rental']), $required->get($helmet->id));
    }

    public function test_an_extra_from_another_entry_is_rejected()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);
        $otherEntry = $this->makeStatamicItemWithAvailability(available: 4);

        $extra = Extra::factory()->create();
        ResrvEntry::whereItemId($otherEntry->id())->extras()->attach($extra->id);

        $this->expectException(ManualReservationException::class);
        $this->expectExceptionMessage('does not belong to this entry');

        $this->creator()->create($this->baseInput($entry, [
            'extras' => [['id' => $extra->id, 'quantity' => 1]],
        ]));
    }

    public function test_an_unpublished_extra_is_rejected()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        $extra = Extra::factory()->create(['published' => false]);
        ResrvEntry::whereItemId($entry->id())->extras()->attach($extra->id);

        $this->expectException(ManualReservationException::class);
        $this->expectExceptionMessage('does not belong to this entry');

        $this->creator()->create($this->baseInput($entry, [
            'extras' => [['id' => $extra->id, 'quantity' => 1]],
        ]));
    }

    public function test_an_extra_in_an_unpublished_category_is_rejected()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        // The quote's extras list and the frontend checkout both hide the extras of an
        // unpublished category (Extra::scopeSelectable()); a stale CP selection must not book one.
        $category = ExtraCategory::factory()->create(['published' => false]);
        $extra = Extra::factory()->create(['category_id' => $category->id]);
        ResrvEntry::whereItemId($entry->id())->extras()->attach($extra->id);

        $this->expectException(ManualReservationException::class);
        $this->expectExceptionMessage('does not belong to this entry');

        $this->creator()->create($this->baseInput($entry, [
            'extras' => [['id' => $extra->id, 'quantity' => 1]],
        ]));
    }

    public function test_a_clamped_extra_snapshots_the_charged_line_so_the_cp_shows_what_was_charged()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        // A dynamic-pricing minimum binds differently per unit (priceForDates: max(25, 100) = 100)
        // and per line (calculatePrice: max(25 × 3, 100) = 100). The booking charges 100 for all
        // three units: price × quantity would display 300, and a rounded per-unit snapshot
        // (33.33 × 3) 99.99 — only the charged line itself reconciles.
        $extra = Extra::factory()->fixed()->create();
        ResrvEntry::whereItemId($entry->id())->extras()->attach($extra->id);

        $dynamic = DynamicPricing::factory()->extra()->create([
            'amount_type' => 'fixed',
            'amount_operation' => 'minimum',
            'amount' => '100',
            'condition_value' => '1',
        ]);
        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $extra->id,
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Extra',
        ]);
        Cache::flush();

        $quote = $this->creator()->quote($this->baseInput($entry, [
            'extras' => [['id' => $extra->id, 'quantity' => 3]],
        ]));

        $this->assertSame('100.00', $quote['pricing']['extras_total']->format());
        $this->assertSame('100.00', $quote['extras'][$extra->id]['price']);

        $reservation = $this->creator()->create($this->baseInput($entry, [
            'extras' => [['id' => $extra->id, 'quantity' => 3]],
        ]));

        $this->assertTrue($reservation->total->equals(Price::create('200.00')));
        $this->assertDatabaseHas('resrv_reservation_extra', [
            'reservation_id' => $reservation->id,
            'extra_id' => $extra->id,
            'quantity' => 3,
            'price' => '100.00',
            'total' => '100.00',
        ]);
        $this->assertSame('100.00', $reservation->extras()->first()->priceFromPivot());
    }

    public function test_a_pivot_row_without_a_line_total_still_displays_price_times_quantity()
    {
        // Checkout-written rows predate the total column and are linear by construction.
        $entry = $this->makeStatamicItemWithAvailability(available: 4);
        $extra = $this->attachExtraTo($entry);

        $reservation = Reservation::factory()->create(['item_id' => $entry->id()]);
        $reservation->extras()->attach($extra->id, ['quantity' => 3, 'price' => '4.65']);

        $this->assertSame('13.95', $reservation->extras()->first()->priceFromPivot());
    }

    public function test_an_extra_that_does_not_allow_multiple_rejects_a_quantity_above_one()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        // Non-multiple extras carry no maximum, so the cap alone would accept any quantity from a crafted payload.
        $extra = Extra::factory()->create(['allow_multiple' => false, 'maximum' => null]);
        ResrvEntry::whereItemId($entry->id())->extras()->attach($extra->id);

        $this->expectException(ManualReservationException::class);
        $this->expectExceptionMessage('cannot be added more than once');

        $this->creator()->create($this->baseInput($entry, [
            'extras' => [['id' => $extra->id, 'quantity' => 3]],
        ]));
    }

    public function test_an_unpublished_option_is_rejected()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        // The create form only exposes published options; a stale or crafted payload must be rejected, not priced.
        $option = Option::factory()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->create(['item_id' => $entry->id(), 'required' => false, 'published' => false]);

        $this->expectException(ManualReservationException::class);
        $this->expectExceptionMessage('does not belong to this entry');

        $this->creator()->create($this->baseInput($entry, [
            'options' => [['id' => $option->id, 'value' => $option->values->first()->id]],
        ]));
    }

    public function test_a_soft_deleted_option_value_is_rejected()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        // Option::calculatePrice() resolves values withTrashed() for historical repricing, so a deleted value must be rejected explicitly.
        $option = Option::factory()
            ->notRequired()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->create(['item_id' => $entry->id()]);
        $optionValue = $option->values->first();
        $optionValue->delete();

        $this->expectException(ManualReservationException::class);
        $this->expectExceptionMessage('no longer available');

        $this->creator()->create($this->baseInput($entry, [
            'options' => [['id' => $option->id, 'value' => $optionValue->id]],
        ]));
    }

    public function test_an_unpublished_option_value_is_rejected()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        // Only published values are listed on the create form; an unpublished one must be rejected, not priced.
        $option = Option::factory()
            ->notRequired()
            ->has(OptionValue::factory()->fixed(), 'values')
            ->create(['item_id' => $entry->id()]);
        $optionValue = $option->values->first();
        $optionValue->update(['published' => false]);

        $this->expectException(ManualReservationException::class);
        $this->expectExceptionMessage('no longer available');

        $this->creator()->create($this->baseInput($entry, [
            'options' => [['id' => $option->id, 'value' => $optionValue->id]],
        ]));
    }

    public function test_quoting_prunes_stale_holds_but_keeps_the_sessions_own_checkout_hold()
    {
        Config::set('resrv-config.minutes_to_hold', 10);

        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        // The quote must prune stale holds from other sessions without abandoning the admin's own in-flight checkout hold.
        $sessionHold = Reservation::factory()->create(['item_id' => $entry->id()]);
        $staleHold = Reservation::factory()->create([
            'item_id' => $entry->id(),
            'created_at' => now()->subMinutes(30),
        ]);
        session(['resrv_reservation' => $sessionHold->id]);

        $this->creator()->quote($this->baseInput($entry), requireCustomAmount: false);

        $this->assertSame('pending', $sessionHold->fresh()->status);
        $this->assertSame('expired', $staleHold->fresh()->status);
    }

    public function test_custom_priced_extra_uses_the_customer_field_multiplier()
    {
        $this->configurePaymentEntry();
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        // price 10, price_type 'custom', driven by the customer form field 'adults'.
        $extra = Extra::factory()->custom()->create();
        ResrvEntry::whereItemId($entry->id())->extras()->attach($extra->id);

        $reservation = $this->creator()->create($this->baseInput($entry, [
            'extras' => [['id' => $extra->id, 'quantity' => 1]],
            'payment_mode' => 'full',
            'payment_gateway' => 'fake',
            'customer' => [
                'email' => 'customer@example.com',
                'first_name' => 'Test',
                'last_name' => 'Customer',
                'repeat_email' => 'customer@example.com',
                'adults' => 3,
            ],
        ]));

        // The stored pivot price is the per-unit custom price 10 × 3 (the 'adults' value), not 10 × 1.
        $pivotPrice = DB::table('resrv_reservation_extra')
            ->where('reservation_id', $reservation->id)
            ->where('extra_id', $extra->id)
            ->value('price');

        $this->assertSame('30.00', Price::create($pivotPrice)->format());
    }

    public function test_a_zero_amount_booking_confirms_without_a_gateway_in_the_cp_flow()
    {
        Mail::fake();

        // Deposit computes to zero (fixed 0) while the total stays positive; no gateway is needed.
        Config::set('resrv-config.payment', 'fixed');
        Config::set('resrv-config.fixed_amount', 0);
        Config::set('resrv-config.full_payment_after_free_cancellation', false);

        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        $reservation = $this->creator()->create($this->baseInput($entry), null, requireGatewayForPayment: true);

        $this->assertSame('confirmed', $reservation->status);
        $this->assertTrue($reservation->payment->isZero());
        $this->assertSame('', $reservation->payment_gateway);
    }

    public function test_a_throwing_confirmation_listener_does_not_fail_a_zero_amount_creation()
    {
        Mail::fake();

        Config::set('resrv-config.payment', 'fixed');
        Config::set('resrv-config.fixed_amount', 0);
        Config::set('resrv-config.full_payment_after_free_cancellation', false);

        // A synchronous confirmation listener throwing after commit must not escape create(): a CP 500 plus retry would double-book.
        Event::listen(ReservationConfirmedEvent::class, function (): void {
            throw new \RuntimeException('listener boom');
        });

        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        $reservation = $this->creator()->create($this->baseInput($entry), null, requireGatewayForPayment: true);

        $this->assertSame('confirmed', $reservation->status);
        $this->assertDatabaseCount('resrv_reservations', 1);
    }

    public function test_a_nonzero_amount_booking_requires_a_gateway_in_the_cp_flow()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        $this->expectException(ManualReservationException::class);
        $this->expectExceptionMessage('A payment method is required');

        // A non-zero amount with no gateway is rejected in the CP flow; direct callers may omit it.
        $this->creator()->create($this->baseInput($entry), null, requireGatewayForPayment: true);
    }

    public function test_a_frontend_session_coupon_never_discounts_a_manual_reservation()
    {
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        // Coupon-gated 20% decrease that engages only while session('resrv_coupon') matches.
        $dynamic = DynamicPricing::factory()->withCoupon()->create();

        DB::table('resrv_dynamic_pricing_assignments')->insert([
            'dynamic_pricing_id' => $dynamic->id,
            'dynamic_pricing_assignment_id' => $entry->id(),
            'dynamic_pricing_assignment_type' => 'Reach\StatamicResrv\Models\Availability',
        ]);

        Cache::forget('dynamic_pricing_table');
        Cache::forget('dynamic_pricing_assignments_table');

        session(['resrv_coupon' => '20OFF']);

        // Sanity: the raw pricing path DOES discount, so the creator must be what blocks it.
        $rawPricing = (new Availability)->getPricing(array_merge($this->dates(), [
            'quantity' => 1,
            'rate_id' => Rate::forEntry($entry->id())->first()?->id,
        ]), $entry->id());
        $this->assertSame('80.00', $rawPricing['price']);

        $quote = $this->creator()->quote($this->baseInput($entry));
        $this->assertSame('100.00', $quote['pricing']['total']->format());

        $reservation = $this->creator()->create($this->baseInput($entry));

        $this->assertSame('100.00', $reservation->total->format());
        $this->assertDatabaseMissing('resrv_reservation_dynamic_pricing', ['reservation_id' => $reservation->id]);

        // The admin's own in-progress frontend checkout keeps its coupon.
        $this->assertSame('20OFF', session('resrv_coupon'));
    }

    public function test_a_frontend_search_session_never_skews_a_manual_quote()
    {
        $this->configurePaymentEntry();
        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        // price 10, price_type 'custom', driven by the customer form field 'adults'.
        $extra = Extra::factory()->custom()->create();
        ResrvEntry::whereItemId($entry->id())->extras()->attach($extra->id);

        // A leftover frontend-search customer payload must not drive Extra::getCustomPrice's session fallback (×4 instead of ×1).
        session(['resrv-search' => (object) ['customer' => ['adults' => 4]]]);

        // Empty customer = the state where the session fallback used to engage.
        $quote = $this->creator()->quote($this->baseInput($entry, [
            'extras' => [['id' => $extra->id, 'quantity' => 1]],
            'customer' => [],
        ]));

        $this->assertSame('110.00', $quote['pricing']['total']->format());

        // The admin's in-progress frontend search survives the quote.
        $this->assertSame(['adults' => 4], session('resrv-search')->customer);
    }

    public function test_an_online_gateway_without_webhook_support_cannot_collect_a_payment()
    {
        Config::set('resrv-config.payment_gateways', [
            'webhookless' => ['class' => WebhooklessOnlineGateway::class, 'label' => 'Webhookless'],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        // Not manually confirmable and no webhook: nothing could ever confirm the booking, so the charge must be refused.
        $this->expectException(ManualReservationException::class);
        $this->expectExceptionMessage('cannot confirm online payments');

        $this->creator()->create($this->baseInput($entry, [
            'payment_mode' => 'full',
            'payment_gateway' => 'webhookless',
        ]));
    }

    public function test_a_blank_gateway_on_a_nonzero_creation_is_pinned_to_the_default_gateway()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Fake',
                'surcharge' => ['type' => 'percent', 'amount' => 10],
            ],
            'offline' => ['class' => OfflinePaymentGateway::class, 'label' => 'Offline'],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        // A blank gateway on a nonzero creation must be pinned to the default gateway and carry its surcharge.
        $reservation = $this->creator()->create($this->baseInput($entry, ['payment_mode' => 'full']));

        $this->assertSame('fake', $reservation->payment_gateway);
        $this->assertSame('100.00', $reservation->payment->format());
        $this->assertSame('10.00', $reservation->payment_surcharge->format());
    }

    public function test_a_blank_gateway_creation_enforces_the_default_gateways_amount_limits()
    {
        Config::set('resrv-config.payment_gateways', [
            'fake' => [
                'class' => FakePaymentGateway::class,
                'label' => 'Fake',
                'amount_limits' => ['max' => 50],
            ],
        ]);
        app()->forgetInstance(PaymentGatewayManager::class);

        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        $this->expectException(ManualReservationException::class);
        $this->expectExceptionMessage('outside the allowed limits');

        $this->creator()->create($this->baseInput($entry, ['payment_mode' => 'full']));
    }

    public function test_a_blank_gateway_creation_requires_the_payment_page_for_an_online_default()
    {
        Config::set('resrv-config.manual_reservations_payment_entry', null);

        $entry = $this->makeStatamicItemWithAvailability(available: 4);

        // The default gateway is online: without a payment page the booking could never be paid.
        $this->expectException(ManualReservationException::class);
        $this->expectExceptionMessage('payment page entry is not configured');

        $this->creator()->create($this->baseInput($entry, ['payment_mode' => 'full']));
    }
}
