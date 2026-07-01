<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Http\Payment\FakePaymentGateway;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Mail\ReservationConfirmed;
use Reach\StatamicResrv\Mail\ReservationMade;
use Reach\StatamicResrv\Mail\ReservationRefunded;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ReservationRefundProcessor;
use Reach\StatamicResrv\Tests\TestCase;

class ReservationCpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_can_index_reservations()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
        ])->withCustomer()->create();

        $response = $this->get(cp_route('resrv.reservation.index'));

        $response->assertStatus(200)->assertSee($reservation->id)->assertSee($item->title);
    }

    public function test_index_payload_exposes_entry_url_for_the_listing_link()
    {
        $item = $this->makeStatamicItem();

        Reservation::factory(['item_id' => $item->id()])->withCustomer()->create();

        $response = $this->getJson(cp_route('resrv.reservation.index'));

        // entryToArray() uses 'url', not 'permalink'.
        $response->assertStatus(200)
            ->assertJsonPath('data.0.entry.url', $item->url());

        $entry = $response->json('data.0.entry');
        $this->assertArrayHasKey('url', $entry);
        $this->assertNotEmpty($entry['url']);
        $this->assertArrayNotHasKey('permalink', $entry);
    }

    public function test_index_payload_only_exposes_customer_email_not_full_pii()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory(['item_id' => $item->id()])->withCustomer()->create();
        $customer = $reservation->customer;

        $response = $this->getJson(cp_route('resrv.reservation.index'));

        $response->assertStatus(200)
            ->assertJsonPath('data.0.customer.email', $customer->email);

        // Only the email is exposed — not the id, timestamps, or the PII `data` blob.
        $customerPayload = $response->json('data.0.customer');
        $this->assertSame(['email'], array_keys($customerPayload));
        $response->assertDontSee($customer->data->get('phone'));
    }

    public function test_index_payload_renders_deleted_entry_as_plain_text()
    {
        Reservation::factory(['item_id' => 'deleted-entry-id'])->withCustomer()->create();

        $response = $this->getJson(cp_route('resrv.reservation.index'));

        // Deleted entries fall back to emptyEntry() with a null url.
        $response->assertStatus(200)
            ->assertJsonPath('data.0.entry.title', '## Entry deleted ##');

        $entry = $response->json('data.0.entry');
        $this->assertArrayHasKey('url', $entry);
        $this->assertNull($entry['url']);
        $this->assertArrayNotHasKey('permalink', $entry);
    }

    // The Listing renders date columns with DateIndexFieldtype, which expects the Date
    // fieldtype's preProcessIndex() payload. A pre-formatted string has no `date` key, so
    // every row would silently render as the current date.
    public function test_index_payload_formats_dates_for_the_date_index_fieldtype()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory(['item_id' => $item->id()])->withCustomer()->create();

        $response = $this->getJson(cp_route('resrv.reservation.index'));

        // Start/end are wall dates: date-only strings, rendered pinned to UTC so they never
        // shift into the viewer's timezone. Timestamps keep their time and render locally.
        $response->assertStatus(200)
            ->assertJsonPath('data.0.date_start.date', $reservation->date_start->format('Y-m-d'))
            ->assertJsonPath('data.0.date_start.mode', 'single')
            ->assertJsonPath('data.0.date_start.format_has_time', false)
            ->assertJsonPath('data.0.date_end.date', $reservation->date_end->format('Y-m-d'))
            ->assertJsonPath('data.0.created_at.date', $reservation->created_at->copy()->utc()->toIso8601ZuluString('millisecond'))
            ->assertJsonPath('data.0.created_at.time_enabled', true)
            ->assertJsonPath('data.0.created_at.format_has_time', true);
    }

    public function test_index_payload_includes_start_and_end_times_when_charging_by_time()
    {
        Config::set('resrv-config.calculate_days_using_time', true);

        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory(['item_id' => $item->id()])->withCustomer()->create();

        $response = $this->getJson(cp_route('resrv.reservation.index'));

        $response->assertStatus(200)
            ->assertJsonPath('data.0.date_start.date', $reservation->date_start->copy()->utc()->toIso8601ZuluString('millisecond'))
            ->assertJsonPath('data.0.date_start.time_enabled', true)
            ->assertJsonPath('data.0.date_start.format_has_time', true);
    }

    public function test_can_search_reservations_by_reference()
    {
        $item = $this->makeStatamicItem();

        $match = Reservation::factory([
            'item_id' => $item->id(),
            'reference' => 'FINDME',
        ])->withCustomer()->create();

        $other = Reservation::factory([
            'item_id' => $item->id(),
            'reference' => 'SOMETHINGELSE',
        ])->withCustomer()->create();

        $response = $this->getJson(cp_route('resrv.reservation.index').'?search=FINDME');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $match->id])
            ->assertJsonMissing(['id' => $other->id]);
    }

    public function test_can_search_reservations_by_customer_email()
    {
        $item = $this->makeStatamicItem();

        $match = Reservation::factory(['item_id' => $item->id()])
            ->for(Customer::factory(['email' => 'searchable@example.com']))
            ->create();

        $other = Reservation::factory(['item_id' => $item->id()])
            ->for(Customer::factory(['email' => 'someone-else@example.com']))
            ->create();

        $response = $this->getJson(cp_route('resrv.reservation.index').'?search=searchable@example.com');

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $match->id])
            ->assertJsonMissing(['id' => $other->id]);
    }

    public function test_searching_reservations_with_no_matches_returns_an_empty_result()
    {
        $item = $this->makeStatamicItem();

        Reservation::factory(['item_id' => $item->id()])->withCustomer()->create();

        $response = $this->getJson(cp_route('resrv.reservation.index').'?search=nonexistentterm');

        $response->assertStatus(200)->assertJsonFragment(['total' => 0]);
    }

    // A bare % is escaped, so it must not behave as a wildcard that returns every reservation.
    public function test_search_escapes_like_wildcards_instead_of_matching_everything()
    {
        $item = $this->makeStatamicItem();
        Reservation::factory(3, ['item_id' => $item->id()])->withCustomer()->create();

        $this->getJson(cp_route('resrv.reservation.index').'?search=%25')
            ->assertStatus(200)
            ->assertJsonFragment(['total' => 0]);
    }

    // An unlisted ?sort= (here a relation column) must be dropped — it would 500 on MySQL/Postgres
    // where ORDER BY validates columns — and replaced with the default created_at. A whitelisted
    // column is passed through unchanged.
    public function test_index_whitelists_the_sort_column()
    {
        $item = $this->makeStatamicItem();
        Reservation::factory(['item_id' => $item->id()])->withCustomer()->create();

        $this->assertListingOrdersBy('created_at', '?sort=customer');
        $this->assertListingOrdersBy('reference', '?sort=reference');
    }

    // A ?order= that is not asc/desc must fall back to desc rather than throwing the
    // InvalidArgumentException that Builder::orderBy() raises for an unknown direction.
    public function test_index_ignores_an_invalid_sort_direction()
    {
        $item = $this->makeStatamicItem();
        Reservation::factory(['item_id' => $item->id()])->withCustomer()->create();

        $this->getJson(cp_route('resrv.reservation.index').'?order=garbage')->assertOk();
    }

    // A reasonable ?perPage passes through, but an excessive one is capped so the listing can't
    // load and serialize an unbounded number of rows.
    public function test_index_clamps_per_page_to_a_safe_maximum()
    {
        $item = $this->makeStatamicItem();
        Reservation::factory(['item_id' => $item->id()])->withCustomer()->create();

        $this->getJson(cp_route('resrv.reservation.index').'?perPage=10')
            ->assertOk()->assertJsonPath('meta.per_page', 10);

        $this->getJson(cp_route('resrv.reservation.index').'?perPage=1000000')
            ->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    private function assertListingOrdersBy(string $column, string $query): void
    {
        DB::enableQueryLog();
        $this->getJson(cp_route('resrv.reservation.index').$query)->assertOk();
        $mainQuery = collect(DB::getQueryLog())->pluck('query')
            ->first(fn ($sql) => str_contains($sql, 'resrv_reservations') && str_contains($sql, 'order by'));
        DB::flushQueryLog();

        $this->assertNotNull($mainQuery, 'Expected a reservations listing query with an order by clause.');
        $this->assertStringContainsString($column, $mainQuery, "Expected the listing to order by {$column} for query {$query}.");
    }

    public function test_reservation_listing_eager_loads_relations_without_n_plus_one()
    {
        $item = $this->makeStatamicItem();

        Reservation::factory(['item_id' => $item->id()])->withCustomer()->create();

        // Warm caches so measurements differ only by per-row relation loading.
        $this->get(cp_route('resrv.reservation.index'))->assertOk();

        $queriesForOneRow = $this->countQueries(
            fn () => $this->get(cp_route('resrv.reservation.index'))->assertOk()
        );

        Reservation::factory(3, ['item_id' => $item->id()])->withCustomer()->create();

        $queriesForFourRows = $this->countQueries(
            fn () => $this->get(cp_route('resrv.reservation.index'))->assertOk()
        );

        // Eager loading keeps query count constant; an N+1 regression would scale with rows.
        $this->assertSame(
            $queriesForOneRow,
            $queriesForFourRows,
            'The reservations listing should run a constant number of queries regardless of row count.'
        );
    }

    public function test_parent_reservation_listing_eager_loads_child_rates_without_n_plus_one()
    {
        $item = $this->makeStatamicItem();

        $this->makeParentReservationWithChild($item->id());

        // Warm caches so measurements differ only by per-row relation loading.
        $this->get(cp_route('resrv.reservation.index'))->assertOk();

        $queriesForOneParent = $this->countQueries(
            fn () => $this->get(cp_route('resrv.reservation.index'))->assertOk()
        );

        foreach (range(1, 3) as $i) {
            $this->makeParentReservationWithChild($item->id());
        }

        $queriesForFourParents = $this->countQueries(
            fn () => $this->get(cp_route('resrv.reservation.index'))->assertOk()
        );

        // getRateLabel() walks childs→rate; without eager loading each parent row adds extra queries.
        $this->assertSame(
            $queriesForOneParent,
            $queriesForFourParents,
            'The reservations listing should run a constant number of queries regardless of parent/child row count.'
        );
    }

    private function makeParentReservationWithChild(string $itemId): Reservation
    {
        $reservation = Reservation::factory([
            'type' => 'parent',
            'item_id' => $itemId,
        ])->withCustomer()->create();

        ChildReservation::factory([
            'reservation_id' => $reservation->id,
        ])->create();

        return $reservation;
    }

    private function countQueries(\Closure $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_can_show_reservations()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
        ])->withCustomer()->create();

        $response = $this->get(cp_route('resrv.reservation.show', $reservation->id));

        $response->assertStatus(200)->assertSee($reservation->id)->assertSee($item->title)->assertSee($reservation->customer->email);
    }

    public function test_can_show_child_reservations()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'type' => 'parent',
            'item_id' => $item->id(),
        ])->withCustomer()->create();

        $child = ChildReservation::factory([
            'reservation_id' => $reservation->id,
        ])->create();

        $response = $this->get(cp_route('resrv.reservation.show', $reservation->id));

        $response->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('resrv::Reservations/Show')
                ->has('reservation.childs', 1)
                ->where('reservation.childs.0.date_end', $child->date_end->format('d-m-Y H:i'))
                ->where('reservation.entry.title', $item->title)
            );
    }

    public function test_can_refund_reservations()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'payment_id' => 'abcedf',
        ])->withCustomer()->create();

        $payload = [
            'id' => $reservation->id,
        ];

        $response = $this->patch(cp_route('resrv.reservation.refund', $payload));

        $response->assertStatus(200)->assertSee($reservation->id);
        Mail::assertSent(ReservationRefunded::class);
    }

    public function test_can_refund_partner_reservation_that_skipped_payment()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'partner',
            'payment_id' => '',
        ])->withCustomer()->create();

        $affiliate = Affiliate::factory()->create();
        $reservation->affiliate()->attach($affiliate->id, ['fee' => $affiliate->fee]);

        // No payment intent was ever created, so the gateway must not be touched at all.
        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldNotReceive('forReservation');
        $manager->shouldNotReceive('gateway');
        app()->instance(PaymentGatewayManager::class, $manager);

        $response = $this->patch(cp_route('resrv.reservation.refund', ['id' => $reservation->id]));

        $response->assertStatus(200)->assertSee($reservation->id);
        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'refunded',
        ]);
        Mail::assertSent(ReservationRefunded::class);
    }

    public function test_refunding_partner_reservation_with_payment_still_calls_gateway()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'partner',
            'payment_id' => 'pi_test123',
        ])->withCustomer()->create();

        $affiliate = Affiliate::factory()->create();
        $reservation->affiliate()->attach($affiliate->id, ['fee' => $affiliate->fee]);

        // A payment intent exists, so the charge must still be refunded through the gateway.
        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('forReservation')
            ->once()
            ->with(Mockery::on(fn ($r) => $r->id === $reservation->id))
            ->andReturn(new FakePaymentGateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $response = $this->patch(cp_route('resrv.reservation.refund', ['id' => $reservation->id]));

        $response->assertStatus(200)->assertSee($reservation->id);
        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'refunded',
        ]);
        Mail::assertSent(ReservationRefunded::class);
    }

    public function test_can_refund_zero_payment_reservation_without_touching_gateway()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'payment' => 0,
            'payment_id' => '',
        ])->withCustomer()->create();

        // Zero-payment checkouts never create a payment intent, so the gateway must not be touched.
        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldNotReceive('forReservation');
        $manager->shouldNotReceive('gateway');
        app()->instance(PaymentGatewayManager::class, $manager);

        $response = $this->patch(cp_route('resrv.reservation.refund', ['id' => $reservation->id]));

        $response->assertStatus(200)->assertSee($reservation->id);
        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'refunded',
        ]);
        Mail::assertSent(ReservationRefunded::class);
    }

    public function test_refunding_pending_reservation_with_cleared_payment_id_still_calls_gateway()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        // A PENDING reservation whose intent was cancelled (payment_id cleared) may still carry
        // an orphaned charge on the gateway, so the refund must go through the gateway and
        // surface its failure instead of silently flipping the status to refunded.
        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'pending',
            'payment_id' => '',
        ])->withCustomer()->create();

        $gateway = Mockery::mock(FakePaymentGateway::class)->makePartial();
        $gateway->shouldReceive('refund')
            ->once()
            ->andThrow(new RefundFailedException('No such payment intent.'));

        $manager = Mockery::mock(PaymentGatewayManager::class);
        $manager->shouldReceive('forReservation')->once()->andReturn($gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $response = $this->patch(cp_route('resrv.reservation.refund', ['id' => $reservation->id]));

        $response->assertStatus(400);
        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'pending',
        ]);
        Mail::assertNothingSent();
    }

    public function test_refund_on_already_refunded_reservation_short_circuits_before_gateway()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'refunded',
            'payment_id' => 'abcedf',
        ])->withCustomer()->create();

        $response = $this->patch(cp_route('resrv.reservation.refund', ['id' => $reservation->id]));

        $response->assertStatus(409)->assertSee('already been refunded');
        Mail::assertNothingSent();
    }

    public function test_refund_on_expired_reservation_is_rejected_before_gateway()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'expired',
            'payment_id' => 'abcedf',
        ])->withCustomer()->create();

        $response = $this->patch(cp_route('resrv.reservation.refund', ['id' => $reservation->id]));

        $response->assertStatus(422)->assertSee('Cannot refund');
        Mail::assertNothingSent();
    }

    public function test_refund_returns_a_retryable_503_when_the_refund_throws_an_unexpected_error()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'payment_id' => 'pi_123',
        ])->withCustomer()->create();

        // An unmapped Throwable (e.g. lock-wait timeout) must yield a retryable 503, not a raw 500.
        $processor = Mockery::mock(ReservationRefundProcessor::class);
        $processor->shouldReceive('refund')
            ->once()
            ->andThrow(new \RuntimeException('SQLSTATE[HY000]: Lock wait timeout exceeded.'));
        app()->instance(ReservationRefundProcessor::class, $processor);

        $response = $this->patch(cp_route('resrv.reservation.refund', ['id' => $reservation->id]));

        $response->assertStatus(503)->assertSee('could not be completed');
        $this->assertDatabaseHas('resrv_reservations', [
            'id' => $reservation->id,
            'status' => 'confirmed',
        ]);
        Mail::assertNothingSent();
    }

    public function test_refunding_a_non_existent_reservation_returns_404()
    {
        Mail::fake();
        $this->withExceptionHandling();

        $response = $this->patch(cp_route('resrv.reservation.refund', ['id' => 99999]));

        $response->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_can_resend_confirmation_email_to_the_customer()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->withCustomer()->create();

        $response = $this->post(cp_route('resrv.reservation.resendConfirmation'), ['id' => $reservation->id]);

        $response->assertStatus(200)->assertSee($reservation->id);

        // Only the customer confirmation is resent — the admin "reservation made" notice is not.
        Mail::assertSent(ReservationConfirmed::class, fn ($mail) => $mail->hasTo($reservation->customer->email));
        Mail::assertNotSent(ReservationMade::class);
    }

    public function test_can_resend_confirmation_email_for_a_partner_reservation()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'partner',
        ])->withCustomer()->create();

        $response = $this->post(cp_route('resrv.reservation.resendConfirmation'), ['id' => $reservation->id]);

        $response->assertStatus(200)->assertSee($reservation->id);
        Mail::assertSent(ReservationConfirmed::class, fn ($mail) => $mail->hasTo($reservation->customer->email));
    }

    public function test_resending_confirmation_is_rejected_for_a_pending_reservation()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'pending',
        ])->withCustomer()->create();

        $response = $this->postJson(cp_route('resrv.reservation.resendConfirmation'), ['id' => $reservation->id]);

        $response->assertStatus(422)->assertSee('Only confirmed reservations');
        Mail::assertNothingSent();
    }

    public function test_resending_confirmation_is_rejected_for_a_refunded_reservation()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'refunded',
        ])->withCustomer()->create();

        $response = $this->postJson(cp_route('resrv.reservation.resendConfirmation'), ['id' => $reservation->id]);

        $response->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_resending_confirmation_is_rejected_when_there_is_no_customer_email()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->create();

        $response = $this->postJson(cp_route('resrv.reservation.resendConfirmation'), ['id' => $reservation->id]);

        $response->assertStatus(422)->assertSee('valid customer email');
        Mail::assertNothingSent();
    }

    // A non-empty but malformed address (e.g. legacy/imported data) would be dropped by the
    // dispatcher and silently send nothing, so the controller must reject it up front instead of
    // reporting success.
    public function test_resending_confirmation_is_rejected_for_a_malformed_customer_email()
    {
        Mail::fake();
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->for(Customer::factory(['email' => 'not-an-email']))->create();

        $response = $this->postJson(cp_route('resrv.reservation.resendConfirmation'), ['id' => $reservation->id]);

        $response->assertStatus(422)->assertSee('valid customer email');
        Mail::assertNothingSent();
    }

    public function test_resending_confirmation_for_a_non_existent_reservation_returns_404()
    {
        Mail::fake();
        $this->withExceptionHandling();

        $response = $this->postJson(cp_route('resrv.reservation.resendConfirmation'), ['id' => 99999]);

        $response->assertNotFound();
        Mail::assertNothingSent();
    }

    // The manual resend must always reach the customer, even if the customer_confirmed event is
    // configured to deliver somewhere else (e.g. only to admins). It is an explicit "send this to
    // the customer" action, so the configured recipients must not redirect it.
    public function test_resending_confirmation_always_targets_the_customer_even_when_event_recipients_are_admins()
    {
        Mail::fake();
        Config::set('resrv-config.admin_email', 'admin@example.com');
        Config::set('resrv-config.reservation_emails_global', [
            ['event' => 'customer_confirmed', 'recipient_sources' => ['admins']],
        ]);

        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->withCustomer()->create();

        $response = $this->post(cp_route('resrv.reservation.resendConfirmation'), ['id' => $reservation->id]);

        $response->assertStatus(200);
        Mail::assertSent(ReservationConfirmed::class, fn ($mail) => $mail->hasTo($reservation->customer->email)
            && ! $mail->hasTo('admin@example.com'));
    }

    public function test_resending_confirmation_overrides_a_disabled_confirmation_email()
    {
        // The enabled=false switch only governs automatic lifecycle sending. A deliberate manual
        // resend is an explicit admin action and intentionally overrides the switch.
        Mail::fake();
        Config::set('resrv-config.reservation_emails_global', [
            ['event' => 'customer_confirmed', 'enabled' => false],
        ]);

        $item = $this->makeStatamicItem();
        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->withCustomer()->create();

        $response = $this->post(cp_route('resrv.reservation.resendConfirmation'), ['id' => $reservation->id]);

        $response->assertStatus(200)->assertSee($reservation->id);
        Mail::assertSent(ReservationConfirmed::class, fn ($mail) => $mail->hasTo($reservation->customer->email));
    }

    public function test_can_query_reservations_calendar_json()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->withCustomer()->create();

        $response = $this->get(cp_route('resrv.reservations.calendar.list').'?start='.urlencode(now()->toIso8601String()).'&end='.urlencode(now()->addMonth()->toIso8601String()));

        $response->assertStatus(200)->assertSee($reservation->id)->assertSee($item->title);
    }

    public function test_calendar_rejects_invalid_dates()
    {
        $this->withExceptionHandling();

        $response = $this->getJson(cp_route('resrv.reservations.calendar.list').'?start=notadate&end=notadate');

        $response->assertStatus(422);
    }

    public function test_can_show_reservations_calendar()
    {
        $response = $this->get(cp_route('resrv.reservations.calendar'));

        $response->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('resrv::Reservations/Calendar'));
    }
}
