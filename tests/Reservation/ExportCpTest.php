<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Reach\StatamicResrv\Http\Controllers\ExportCpController;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Models\Extra;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\CreatesEntries;
use Reach\StatamicResrv\Tests\TestCase;

class ExportCpTest extends TestCase
{
    use CreatesEntries, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
        Cache::forget(ExportCpController::CUSTOMER_KEYS_CACHE_KEY);
    }

    public function test_can_show_export_page()
    {
        $response = $this->get(cp_route('resrv.export.index'));

        $response->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('resrv::Export/Index'));
    }

    public function test_count_endpoint_returns_matching_reservations()
    {
        $item = $this->makeStatamicItem();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
        ])->count(3)->withCustomer()->create();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'date_start' => today()->subYear()->toIso8601String(),
            'date_end' => today()->subYear()->addDays(2)->toIso8601String(),
        ])->count(2)->withCustomer()->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->get(cp_route('resrv.export.count')."?start={$start}&end={$end}")
            ->assertStatus(200)
            ->assertJson(['count' => 3]);
    }

    public function test_count_endpoint_filters_by_status()
    {
        $item = $this->makeStatamicItem();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->count(2)->withCustomer()->create();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'expired',
        ])->count(3)->withCustomer()->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->get(cp_route('resrv.export.count')."?start={$start}&end={$end}&statuses[]=confirmed")
            ->assertStatus(200)
            ->assertJson(['count' => 2]);
    }

    public function test_count_endpoint_filters_by_item()
    {
        $first = $this->makeStatamicItem();
        $second = $this->makeStatamicItem();

        Reservation::factory(['item_id' => $first->id(), 'status' => 'confirmed'])->count(2)->withCustomer()->create();
        Reservation::factory(['item_id' => $second->id(), 'status' => 'confirmed'])->count(4)->withCustomer()->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->get(cp_route('resrv.export.count')."?start={$start}&end={$end}&item_id={$first->id()}")
            ->assertStatus(200)
            ->assertJson(['count' => 2]);
    }

    public function test_count_endpoint_filters_by_affiliate()
    {
        $item = $this->makeStatamicItem();
        $affiliate = Affiliate::factory()->create();

        $reservations = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->count(2)->withCustomer()->create();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->count(3)->withCustomer()->create();

        foreach ($reservations as $reservation) {
            $reservation->affiliate()->attach($affiliate->id, ['fee' => 0]);
        }

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->get(cp_route('resrv.export.count')."?start={$start}&end={$end}&affiliate_id={$affiliate->id}")
            ->assertStatus(200)
            ->assertJson(['count' => 2]);
    }

    public function test_count_endpoint_filters_by_customer_data_presence()
    {
        $item = $this->makeStatamicItem();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'expired',
        ])->count(2)->withCustomer()->create();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'expired',
        ])->count(3)->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->get(cp_route('resrv.export.count')."?start={$start}&end={$end}&with_customer_data=1")
            ->assertStatus(200)
            ->assertJson(['count' => 2]);
    }

    public function test_download_endpoint_returns_csv_with_selected_fields()
    {
        $item = $this->makeStatamicItem(['title' => 'Beach House']);

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'reference' => 'AAA111',
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
        ])->withCustomer()->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $response = $this->post(cp_route('resrv.export.download'), [
            'start' => $start,
            'end' => $end,
            'fields' => ['reference', 'status', 'entry_title', 'customer_email'],
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertEquals(['Reference', 'Status', 'Item', 'Email'], $rows[0]);
        $this->assertEquals('AAA111', $rows[1][0]);
        $this->assertEquals('confirmed', $rows[1][1]);
        $this->assertEquals('Beach House', $rows[1][2]);
        $this->assertEquals($reservation->customer->email, $rows[1][3]);
    }

    public function test_download_endpoint_renders_extras_and_options()
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->withCustomer()->create();

        $extra = Extra::factory()->create(['name' => 'Breakfast']);
        $reservation->extras()->attach($extra->id, ['quantity' => 2, 'price' => 10]);

        $option = Option::factory()->create(['name' => 'Bed type', 'item_id' => $item->id()]);
        $value = OptionValue::factory()->create(['option_id' => $option->id, 'name' => 'King']);
        $reservation->options()->attach($option->id, ['value' => $value->id]);

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $response = $this->post(cp_route('resrv.export.download'), [
            'start' => $start,
            'end' => $end,
            'fields' => ['extras', 'options'],
        ]);

        $response->assertStatus(200);
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Breakfast × 2', $csv);
        $this->assertStringContainsString('Bed type: King', $csv);
    }

    public function test_download_validates_field_whitelist()
    {
        $this->withExceptionHandling();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->postJson(cp_route('resrv.export.download'), [
            'start' => $start,
            'end' => $end,
            'fields' => ['password'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fields.0']);
    }

    public function test_download_requires_at_least_one_field()
    {
        $this->withExceptionHandling();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->postJson(cp_route('resrv.export.download'), ['start' => $start, 'end' => $end])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fields']);
    }

    /**
     * The download route is POST-only so field selections travel in the body
     * instead of a length-limited GET URL. Statamic's CP catch-all turns the
     * unmatched GET into a 404 rather than a 405.
     */
    public function test_download_rejects_get_requests()
    {
        $this->withExceptionHandling();

        $this->get(cp_route('resrv.export.download').'?start=2026-01-01&end=2026-01-31&fields[]=reference')
            ->assertStatus(404);
    }

    public function test_count_validates_date_range()
    {
        $this->withExceptionHandling();

        $this->getJson(cp_route('resrv.export.count').'?start=2026-05-10&end=2026-05-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end']);
    }

    public function test_export_page_lists_custom_customer_keys_discovered_in_database()
    {
        Customer::factory()->create([
            'data' => collect([
                'first_name' => 'Test',
                'tax_id' => 'EL123',
                'company' => 'Acme',
            ]),
        ]);

        $response = $this->get(cp_route('resrv.export.index'));

        $response->assertStatus(200);
        $response->assertSee('customer_tax_id');
        $response->assertSee('customer_company');
    }

    public function test_download_includes_dynamically_discovered_customer_field()
    {
        $item = $this->makeStatamicItem();

        $customer = Customer::factory()->create([
            'data' => collect([
                'first_name' => 'Iosif',
                'tax_id' => 'EL123456789',
            ]),
        ]);

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'reference' => 'TAX001',
            'customer_id' => $customer->id,
        ])->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $response = $this->post(cp_route('resrv.export.download'), [
            'start' => $start,
            'end' => $end,
            'fields' => ['reference', 'customer_tax_id'],
        ]);

        $response->assertStatus(200);
        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertEquals(['Reference', 'Tax Id'], $rows[0]);
        $this->assertEquals('TAX001', $rows[1][0]);
        $this->assertEquals('EL123456789', $rows[1][1]);
    }

    public function test_csv_injection_with_leading_whitespace_is_sanitized()
    {
        $item = $this->makeStatamicItem();

        $customer = Customer::factory()->create([
            'data' => collect([
                'first_name' => '  =1+1',
            ]),
        ]);

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'reference' => 'INJ001',
            'customer_id' => $customer->id,
        ])->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $response = $this->post(cp_route('resrv.export.download'), [
            'start' => $start,
            'end' => $end,
            'fields' => ['customer_first_name'],
        ]);

        $response->assertStatus(200);
        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertSame("'  =1+1", $rows[1][0]);
    }

    public function test_download_includes_rate_title_and_slug_for_rate_assigned_reservation()
    {
        $entries = $this->createRateEntries();
        $entry = $entries->first();
        $rate = Rate::where('collection', $entry->collection()->handle())
            ->where('slug', 'test')
            ->firstOrFail();

        Reservation::factory([
            'item_id' => $entry->id(),
            'status' => 'confirmed',
            'reference' => 'PROP001',
            'rate_id' => $rate->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
        ])->withCustomer()->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $response = $this->post(cp_route('resrv.export.download'), [
            'start' => $start,
            'end' => $end,
            'fields' => ['reference', 'entry_rate', 'entry_rate_slug'],
        ]);

        $response->assertStatus(200);
        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertEquals(['Reference', 'Rate', 'Rate slug'], $rows[0]);
        $this->assertEquals('PROP001', $rows[1][0]);
        $this->assertEquals($rate->title, $rows[1][1]);
        $this->assertEquals('test', $rows[1][2]);
    }

    public function test_download_keeps_rate_columns_when_entry_is_deleted()
    {
        $entries = $this->createRateEntries();
        $entry = $entries->first();
        $itemId = $entry->id();
        $rate = Rate::where('collection', $entry->collection()->handle())
            ->where('slug', 'test')
            ->firstOrFail();

        Reservation::factory([
            'item_id' => $itemId,
            'status' => 'confirmed',
            'reference' => 'GONE001',
            'rate_id' => $rate->id,
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
        ])->withCustomer()->create();

        $entry->delete();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $response = $this->post(cp_route('resrv.export.download'), [
            'start' => $start,
            'end' => $end,
            'fields' => ['reference', 'entry_rate', 'entry_rate_slug'],
        ]);

        $response->assertStatus(200);
        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertEquals('GONE001', $rows[1][0]);
        $this->assertEquals($rate->title, $rows[1][1]);
        $this->assertEquals('test', $rows[1][2]);
    }

    public function test_download_returns_blank_rate_columns_for_reservation_without_rate()
    {
        $item = $this->makeStatamicItem();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'reference' => 'NOPROP',
            'rate_id' => null,
        ])->withCustomer()->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $response = $this->post(cp_route('resrv.export.download'), [
            'start' => $start,
            'end' => $end,
            'fields' => ['reference', 'entry_rate', 'entry_rate_slug'],
        ]);

        $response->assertStatus(200);
        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertEquals('NOPROP', $rows[1][0]);
        $this->assertSame('', $rows[1][1]);
        $this->assertSame('', $rows[1][2]);
    }

    public function test_download_does_not_query_child_rates_per_parent_reservation()
    {
        $entries = $this->createRateEntries();
        $entry = $entries->first();
        $rate = Rate::where('collection', $entry->collection()->handle())
            ->where('slug', 'test')
            ->firstOrFail();

        $makeParent = function (string $ref) use ($entry, $rate) {
            $parent = Reservation::factory([
                'item_id' => $entry->id(),
                'status' => 'confirmed',
                'reference' => $ref,
                'type' => 'parent',
                'date_start' => today()->toIso8601String(),
                'date_end' => today()->addDays(2)->toIso8601String(),
            ])->withCustomer()->create();

            ChildReservation::factory()->withRate($rate->id)->count(2)->create([
                'reservation_id' => $parent->id,
            ]);
        };

        $payload = [
            'start' => today()->toDateString(),
            'end' => today()->addWeek()->toDateString(),
            'fields' => ['reference', 'entry_rate', 'entry_rate_slug'],
        ];

        $countChildQueries = function () use ($payload) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->post(cp_route('resrv.export.download'), $payload)->streamedContent();

            $count = collect(DB::getQueryLog())
                ->filter(fn ($query) => str_contains($query['query'], 'resrv_child_reservations'))
                ->count();

            DB::disableQueryLog();

            return $count;
        };

        $makeParent('PARENT1');
        $makeParent('PARENT2');
        $twoParents = $countChildQueries();

        $makeParent('PARENT3');
        $makeParent('PARENT4');
        $fourParents = $countChildQueries();

        // Child rates are eager-loaded once per chunk, not re-queried per parent row.
        $this->assertSame(
            $twoParents,
            $fourParents,
            'Export must not issue a child-reservation query per parent reservation row.'
        );
    }

    public function test_dynamic_discovery_does_not_duplicate_standard_customer_keys()
    {
        Customer::factory()->create([
            'data' => collect([
                'first_name' => 'Iosif',
                'last_name' => 'Chatzimichail',
                'phone' => '123',
            ]),
        ]);

        $response = $this->get(cp_route('resrv.export.index'));

        $response->assertStatus(200);
        $body = $response->getContent();

        $count = substr_count($body, 'customer_first_name');
        $this->assertSame(1, $count, 'customer_first_name should appear exactly once in the field metadata');
    }

    public function test_download_includes_affiliate_columns_for_active_commission()
    {
        $affiliate = Affiliate::factory()->create([
            'code' => 'LARRY',
            'name' => 'Larry',
            'email' => 'larry@example.com',
        ]);
        $reservation = $this->makeAffiliateReservation($affiliate, ['reference' => 'AFF001'], 20);

        $response = $this->post(cp_route('resrv.export.download'), [
            'start' => today()->toDateString(),
            'end' => today()->addWeek()->toDateString(),
            'fields' => ['reference', 'affiliate_code', 'affiliate_name', 'affiliate_email', 'affiliate_fee', 'affiliate_commission', 'affiliate_commission_status'],
        ]);

        $response->assertStatus(200);
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($response->streamedContent()))));

        $this->assertEquals(
            ['Reference', 'Affiliate code', 'Affiliate name', 'Affiliate email', 'Affiliate fee (%)', 'Affiliate commission', 'Commission status'],
            $rows[0]
        );
        $this->assertEquals('AFF001', $rows[1][0]);
        $this->assertEquals('LARRY', $rows[1][1]);
        $this->assertEquals('Larry', $rows[1][2]);
        $this->assertEquals('larry@example.com', $rows[1][3]);
        $this->assertEquals('20', $rows[1][4]);
        $this->assertEquals($reservation->total->multiply(0.2)->format(), $rows[1][5]);
        $this->assertEquals('active', $rows[1][6]);
    }

    public function test_download_shows_zero_commission_and_cancelled_status_for_cancelled_commission()
    {
        $affiliate = Affiliate::factory()->create();
        $reservation = $this->makeAffiliateReservation($affiliate, ['reference' => 'AFF002'], 20, cancelled: true);

        $response = $this->post(cp_route('resrv.export.download'), [
            'start' => today()->toDateString(),
            'end' => today()->addWeek()->toDateString(),
            'fields' => ['reference', 'affiliate_commission', 'affiliate_commission_status'],
        ]);

        $response->assertStatus(200);
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($response->streamedContent()))));

        $this->assertEquals('AFF002', $rows[1][0]);
        $this->assertEquals($reservation->total->multiply(0)->format(), $rows[1][1]);
        $this->assertEquals('cancelled', $rows[1][2]);
    }

    public function test_download_leaves_affiliate_columns_blank_when_no_affiliate()
    {
        $item = $this->makeStatamicItem();
        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'reference' => 'NOAFF',
            'total' => 20000,
        ])->withCustomer()->create();

        $response = $this->post(cp_route('resrv.export.download'), [
            'start' => today()->toDateString(),
            'end' => today()->addWeek()->toDateString(),
            'fields' => ['reference', 'affiliate_name', 'affiliate_commission', 'affiliate_commission_status'],
        ]);

        $response->assertStatus(200);
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($response->streamedContent()))));

        $this->assertEquals('NOAFF', $rows[1][0]);
        $this->assertSame('', $rows[1][1]);
        $this->assertSame('', $rows[1][2]);
        $this->assertSame('', $rows[1][3]);
    }

    public function test_count_filters_by_active_commission_status()
    {
        $affiliate = Affiliate::factory()->create();
        $this->makeAffiliateReservation($affiliate, ['reference' => 'A1'], 20);
        $this->makeAffiliateReservation($affiliate, ['reference' => 'A2'], 20);
        $this->makeAffiliateReservation($affiliate, ['reference' => 'C1'], 20, cancelled: true);

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->get(cp_route('resrv.export.count')."?start={$start}&end={$end}&commission_status=active")
            ->assertStatus(200)
            ->assertJson(['count' => 2]);
    }

    public function test_count_filters_by_cancelled_commission_status()
    {
        $affiliate = Affiliate::factory()->create();
        $this->makeAffiliateReservation($affiliate, ['reference' => 'A1'], 20);
        $this->makeAffiliateReservation($affiliate, ['reference' => 'C1'], 20, cancelled: true);

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->get(cp_route('resrv.export.count')."?start={$start}&end={$end}&commission_status=cancelled")
            ->assertStatus(200)
            ->assertJson(['count' => 1]);
    }

    public function test_commission_status_filter_combines_with_affiliate_id()
    {
        $affiliateA = Affiliate::factory()->create(['code' => 'AAA', 'email' => 'a@example.com']);
        $affiliateB = Affiliate::factory()->create(['code' => 'BBB', 'email' => 'b@example.com']);

        $this->makeAffiliateReservation($affiliateA, ['reference' => 'A_ACTIVE'], 20);
        $this->makeAffiliateReservation($affiliateA, ['reference' => 'A_CANCELLED'], 20, cancelled: true);
        $this->makeAffiliateReservation($affiliateB, ['reference' => 'B_ACTIVE'], 20);

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->get(cp_route('resrv.export.count')."?start={$start}&end={$end}&affiliate_id={$affiliateA->id}&commission_status=active")
            ->assertStatus(200)
            ->assertJson(['count' => 1]);
    }

    public function test_download_respects_active_commission_status_filter()
    {
        $affiliate = Affiliate::factory()->create();
        $this->makeAffiliateReservation($affiliate, ['reference' => 'KEEP'], 20);
        $this->makeAffiliateReservation($affiliate, ['reference' => 'DROP'], 20, cancelled: true);

        $response = $this->post(cp_route('resrv.export.download'), [
            'start' => today()->toDateString(),
            'end' => today()->addWeek()->toDateString(),
            'commission_status' => 'active',
            'fields' => ['reference', 'affiliate_commission_status'],
        ]);

        $response->assertStatus(200);
        $csv = $response->streamedContent();

        $this->assertStringContainsString('KEEP', $csv);
        $this->assertStringNotContainsString('DROP', $csv);
    }

    public function test_soft_deleted_affiliates_stay_in_filters_columns_and_the_picker()
    {
        $affiliate = Affiliate::factory()->create(['code' => 'GONE', 'name' => 'Gone Agency']);
        $this->makeAffiliateReservation($affiliate, ['reference' => 'HIST1'], 20);

        $affiliate->delete();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        // Affiliates are soft-deleted precisely so commission history survives — the
        // whereHas filter must not let the SoftDeletes scope drop their reservations.
        $this->get(cp_route('resrv.export.count')."?start={$start}&end={$end}&affiliate_id={$affiliate->id}")
            ->assertStatus(200)
            ->assertJson(['count' => 1]);

        $response = $this->post(cp_route('resrv.export.download'), [
            'start' => $start,
            'end' => $end,
            'affiliate_id' => $affiliate->id,
            'fields' => ['reference', 'affiliate_code', 'affiliate_name', 'affiliate_commission_status'],
        ]);

        $response->assertStatus(200);
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($response->streamedContent()))));

        $this->assertEquals(['HIST1', 'GONE', 'Gone Agency', 'active'], $rows[1]);

        // The export page picker must still offer the deleted affiliate so its history
        // remains filterable.
        $this->get(cp_route('resrv.export.index'))
            ->assertStatus(200)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('resrv::Export/Index')
                ->where('affiliates.0.code', 'GONE')
            );
    }

    public function test_download_serializes_the_filtered_affiliate_when_a_reservation_has_multiple()
    {
        $cookieAffiliate = Affiliate::factory()->create(['code' => 'COOKIE', 'name' => 'Cookie', 'email' => 'cookie@example.com']);
        $couponAffiliate = Affiliate::factory()->create(['code' => 'COUPON', 'name' => 'Coupon', 'email' => 'coupon@example.com']);

        // One reservation, two attributions (e.g. cookie tracking + an affiliate coupon).
        $reservation = $this->makeAffiliateReservation($cookieAffiliate, ['reference' => 'MULTI1'], 20);
        $reservation->affiliate()->attach($couponAffiliate->id, ['fee' => 10]);

        $response = $this->post(cp_route('resrv.export.download'), [
            'start' => today()->toDateString(),
            'end' => today()->addWeek()->toDateString(),
            'affiliate_id' => $couponAffiliate->id,
            'fields' => ['reference', 'affiliate_code', 'affiliate_fee', 'affiliate_commission'],
        ]);

        $response->assertStatus(200);
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($response->streamedContent()))));

        // The affiliate_* columns must describe the affiliate the export was filtered by,
        // not whichever pivot happens to come first.
        $this->assertEquals('MULTI1', $rows[1][0]);
        $this->assertEquals('COUPON', $rows[1][1]);
        $this->assertEquals('10', $rows[1][2]);
        $this->assertEquals($reservation->total->multiply(0.1)->format(), $rows[1][3]);
    }

    protected function makeAffiliateReservation(Affiliate $affiliate, array $attributes = [], float $fee = 20, bool $cancelled = false): Reservation
    {
        $item = $this->makeStatamicItem();

        $reservation = Reservation::factory(array_merge([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'date_start' => today()->toIso8601String(),
            'date_end' => today()->addDays(2)->toIso8601String(),
            'total' => 20000,
        ], $attributes))->withCustomer()->create();

        $reservation->affiliate()->attach($affiliate->id, ['fee' => $fee]);

        if ($cancelled) {
            DB::table('resrv_reservation_affiliate')
                ->where('reservation_id', $reservation->id)
                ->update(['cancelled_at' => now()]);
        }

        return $reservation;
    }
}
