<?php

namespace Reach\StatamicResrv\Tests\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\DynamicPricing;
use Reach\StatamicResrv\Models\Report;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Money\Price;
use Reach\StatamicResrv\Tests\TestCase;

class ReportsCpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAdmin();
    }

    public function test_can_show_reports_page()
    {
        $response = $this->get(cp_route('resrv.reports.index'));
        $response->assertStatus(200)->assertSee('Reports');
    }

    public function test_can_get_report_data()
    {
        $item = $this->makeStatamicItem();
        $item2 = $this->makeStatamicItem();

        $reservation = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
        ])->count(4)->create();

        $reservation2 = Reservation::factory([
            'item_id' => $item2->id(),
            'status' => 'confirmed',
        ])->count(2)->create();

        $response = $this->get(cp_route('resrv.report.index').'?start='.now()->toDateString().'&end='.now()->addWeek()->toDateString());
        $response->assertStatus(200)
            ->assertJson([
                'total_reservations' => 6,
                'total_revenue' => '1200.00',
                'avg_revenue' => '200.00',
            ])
            ->assertSee('Test Statamic Item');
    }

    // Revenue is accumulated in integer minor units and returned as a Price, not summed as floats
    // (which CLAUDE.md forbids). Reverting to Collection::sum() on formatted strings would return a
    // float and fail the instanceof assertion.
    public function test_sum_and_avg_revenue_return_exact_money()
    {
        $item = $this->makeStatamicItem();

        Reservation::factory(['item_id' => $item->id(), 'status' => 'confirmed', 'price' => '10.01'])
            ->count(3)->create();

        $report = new Report(now()->toDateString(), now()->addWeek()->toDateString());

        $this->assertInstanceOf(Price::class, $report->sumRevenue());
        $this->assertSame('30.03', $report->sumRevenue()->format());
        $this->assertSame('10.01', $report->avgRevenue()->format());

        // Per-item revenue aggregates through the same Price path; the payload stays numeric
        // (floats) on purpose — ReportsItemsTable.vue only number-sorts `typeof === 'number'`
        // values, so switching to formatted strings would silently break the revenue sort.
        $top = $report->topSellerItems();
        $this->assertSame(30.03, $top[0]['total_revenue']);
        $this->assertSame(10.01, $top[0]['avg_revenue']);
    }

    public function test_can_get_report_data_filtered_by_created_at()
    {
        $this->travelTo(today()->setHour(12));

        $item = $this->makeStatamicItem();

        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'date_start' => today()->subYear()->toIso8601String(),
            'date_end' => today()->subYear()->addDays(2)->toIso8601String(),
        ])->count(3)->create();

        $start = today()->toDateString();
        $end = today()->addWeek()->toDateString();

        $this->get(cp_route('resrv.report.index')."?start={$start}&end={$end}&date_field=created_at")
            ->assertStatus(200)
            ->assertJson(['total_reservations' => 3]);

        $this->get(cp_route('resrv.report.index')."?start={$start}&end={$end}&date_field=date_start")
            ->assertStatus(200)
            ->assertJson(['total_reservations' => 0]);
    }

    // occurrences (count), revenue, and percentage must all use the same status set
    // (confirmed + partner) — not count confirmed-only while summing over both.
    public function test_top_seller_items_counts_confirmed_and_partner_consistently()
    {
        $itemA = $this->makeStatamicItem();
        $itemB = $this->makeStatamicItem();

        Reservation::factory(['item_id' => $itemA->id(), 'status' => 'confirmed', 'quantity' => 2])->count(3)->create();
        Reservation::factory(['item_id' => $itemA->id(), 'status' => 'partner', 'quantity' => 3])->count(1)->create();
        Reservation::factory(['item_id' => $itemB->id(), 'status' => 'confirmed', 'quantity' => 4])->count(2)->create();

        $top = (new Report(now()->toDateString(), now()->addWeek()->toDateString()))->topSellerItems();

        // Ordered by count desc: A (3 confirmed + 1 partner = 4), then B (2). 6 total.
        // Each row carries the unique item_id it was grouped by (used as the Vue row key).
        $this->assertEquals($itemA->id(), $top[0]['id']);
        $this->assertEquals($itemB->id(), $top[1]['id']);
        $this->assertEquals(4, $top[0]['reservations']);
        $this->assertSame(9, $top[0]['quantity_sold']);
        $this->assertSame(800.0, $top[0]['total_revenue']);
        $this->assertSame(200.0, $top[0]['avg_revenue']);
        $this->assertEquals(0.67, $top[0]['percentage']);

        $this->assertEquals(2, $top[1]['reservations']);
        $this->assertSame(8, $top[1]['quantity_sold']);
        $this->assertSame(400.0, $top[1]['total_revenue']);
        $this->assertSame(200.0, $top[1]['avg_revenue']);
        $this->assertEquals(0.33, $top[1]['percentage']);
    }

    // Follow the money: a no-refund cancellation kept its payment, so the booking stays in the
    // report; refunds and no-charge voids (empty payment_id) drop out. Every section shares the
    // same reservation set, so the top-seller counts and percentages must agree with the totals.
    public function test_report_includes_cancelled_reservations_that_kept_their_payment()
    {
        $item = $this->makeStatamicItem();

        Reservation::factory(['item_id' => $item->id(), 'status' => 'confirmed'])->count(2)->create();
        Reservation::factory(['item_id' => $item->id(), 'status' => 'cancelled', 'payment_id' => 'pi_kept'])->create();
        Reservation::factory(['item_id' => $item->id(), 'status' => 'cancelled'])->create();
        Reservation::factory(['item_id' => $item->id(), 'status' => 'refunded', 'payment_id' => 'pi_returned'])->create();

        $report = new Report(now()->toDateString(), now()->addWeek()->toDateString());

        $this->assertSame(3, $report->countReservations());
        $this->assertSame('600.00', $report->sumRevenue()->format());

        $top = $report->topSellerItems();
        $this->assertEquals(3, $top[0]['reservations']);
        $this->assertEquals(1.0, $top[0]['percentage']);
    }

    // A cancelled unpaid hold can keep its payment_id only as a reconciliation handle on an
    // unverifiable intent (payment_unresolved) — no money was collected, so it must not report.
    public function test_report_excludes_cancelled_reservations_with_an_unresolved_payment_reference()
    {
        $item = $this->makeStatamicItem();

        Reservation::factory(['item_id' => $item->id(), 'status' => 'confirmed'])->create();
        Reservation::factory(['item_id' => $item->id(), 'status' => 'cancelled', 'payment_id' => 'pi_kept'])->create();
        Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'cancelled',
            'payment_id' => 'pi_unresolved',
            'payment_unresolved' => true,
        ])->create();

        $report = new Report(now()->toDateString(), now()->addWeek()->toDateString());

        $this->assertSame(2, $report->countReservations());
        $this->assertSame('400.00', $report->sumRevenue()->format());
    }

    // The retained commission of a paid no-refund cancellation must report; a voided pivot on a
    // loaded reservation must not. The second half can't occur through current flows (voiding and
    // the status whitelist track together), so it's manufactured — the explicit cancelled_at
    // filter is the contract, not that coincidence.
    public function test_affiliate_sales_count_retained_commissions_and_skip_voided_pivots()
    {
        $item = $this->makeStatamicItem();
        $affiliate = Affiliate::factory()->create(['name' => 'Affiliate A', 'code' => 'AFA', 'fee' => 20]);

        $kept = Reservation::factory(['item_id' => $item->id(), 'status' => 'cancelled', 'payment_id' => 'pi_kept', 'total' => '300.00'])->create();
        $kept->affiliate()->attach($affiliate->id, ['fee' => $affiliate->fee]);

        $voided = Reservation::factory(['item_id' => $item->id(), 'status' => 'confirmed', 'total' => '500.00'])->create();
        $voided->affiliate()->attach($affiliate->id, ['fee' => $affiliate->fee, 'cancelled_at' => now()]);

        $row = (new Report(now()->toDateString(), now()->addWeek()->toDateString()))->affiliateSales()->firstWhere('id', $affiliate->id);

        $this->assertEquals(1, $row['reservations']);
        $this->assertSame(300.0, $row['total_revenue']);
        $this->assertSame(60.0, $row['commission']);
    }

    public function test_validates_date_field()
    {
        $this->travelTo(today()->setHour(12));
        $this->withExceptionHandling();

        $this->getJson(cp_route('resrv.report.index').'?start='.today()->toDateString().'&end='.today()->addWeek()->toDateString().'&date_field=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_field']);
    }

    public function test_affiliate_sales_aggregate_reservations_revenue_and_commission_per_affiliate()
    {
        $item = $this->makeStatamicItem();

        // 20% fee, two reservations at 300.00 → 600.00 sales, 120.00 commission.
        $affiliateA = Affiliate::factory()->create(['name' => 'Affiliate A', 'code' => 'AFA', 'fee' => 20]);
        // 10% fee, one reservation at 500.00 → 500.00 sales, 50.00 commission.
        $affiliateB = Affiliate::factory()->create(['name' => 'Affiliate B', 'code' => 'AFB', 'fee' => 10]);

        $reservationsA = Reservation::factory(['item_id' => $item->id(), 'status' => 'confirmed', 'total' => '300.00'])->count(2)->create();
        $reservationB = Reservation::factory(['item_id' => $item->id(), 'status' => 'confirmed', 'total' => '500.00'])->create();

        $reservationsA->each(fn ($reservation) => $reservation->affiliate()->attach($affiliateA->id, ['fee' => $affiliateA->fee]));
        $reservationB->affiliate()->attach($affiliateB->id, ['fee' => $affiliateB->fee]);

        $sales = (new Report(now()->toDateString(), now()->addWeek()->toDateString()))->affiliateSales()->keyBy('id');

        $this->assertSame('Affiliate A', $sales[$affiliateA->id]['title']);
        $this->assertFalse($sales[$affiliateA->id]['deleted']);
        $this->assertEquals(2, $sales[$affiliateA->id]['reservations']);
        $this->assertSame(600.0, $sales[$affiliateA->id]['total_revenue']);
        $this->assertSame(120.0, $sales[$affiliateA->id]['commission']);

        $this->assertEquals(1, $sales[$affiliateB->id]['reservations']);
        $this->assertSame(500.0, $sales[$affiliateB->id]['total_revenue']);
        $this->assertSame(50.0, $sales[$affiliateB->id]['commission']);
    }

    public function test_affiliate_sales_are_omitted_when_affiliates_are_disabled()
    {
        Config::set('resrv-config.enable_affiliates', false);

        $item = $this->makeStatamicItem();
        Reservation::factory(['item_id' => $item->id(), 'status' => 'confirmed'])->create();

        $response = $this->getJson(cp_route('resrv.report.index').'?start='.now()->toDateString().'&end='.now()->addWeek()->toDateString());
        $response->assertStatus(200);

        $this->assertArrayNotHasKey('affiliate_sales', $response->json());
        $this->assertArrayHasKey('dynamic_pricing_applications', $response->json());
    }

    // The affiliate report must survive deletion: a soft-deleted affiliate resolves its name via
    // withTrashed, and a hard-removed one falls back to the pivot snapshot.
    public function test_affiliate_sales_survive_a_deleted_affiliate()
    {
        $item = $this->makeStatamicItem();
        $affiliate = Affiliate::factory()->create(['name' => 'Gone Affiliate', 'code' => 'GONE', 'fee' => 20]);
        $reservation = Reservation::factory(['item_id' => $item->id(), 'status' => 'confirmed', 'total' => '300.00'])->create();
        $reservation->affiliate()->attach($affiliate->id, [
            'fee' => $affiliate->fee,
            'data' => json_encode(['name' => $affiliate->name, 'code' => $affiliate->code]),
        ]);

        $this->delete(cp_route('resrv.affiliate.delete', $affiliate->id))->assertStatus(200);

        $row = (new Report(now()->toDateString(), now()->addWeek()->toDateString()))->affiliateSales()->firstWhere('id', $affiliate->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['deleted']);
        $this->assertSame('Gone Affiliate', $row['title']);
        $this->assertSame(300.0, $row['total_revenue']);
        $this->assertSame(60.0, $row['commission']);

        // Even after a hard delete the snapshot keeps the history readable.
        $affiliate->forceDelete();

        $row = (new Report(now()->toDateString(), now()->addWeek()->toDateString()))->affiliateSales()->firstWhere('id', $affiliate->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['deleted']);
        $this->assertSame('Gone Affiliate', $row['title']);
        $this->assertSame(60.0, $row['commission']);
    }

    public function test_dynamic_pricing_applications_are_counted_per_rule()
    {
        $item = $this->makeStatamicItem();
        $ruleA = DynamicPricing::factory()->create(['title' => 'Rule A']);
        $ruleB = DynamicPricing::factory()->create(['title' => 'Rule B']);

        $reservations = Reservation::factory(['item_id' => $item->id(), 'status' => 'confirmed'])->count(4)->create();
        $reservations->take(3)->each(fn ($reservation) => $reservation->dynamicPricings()->attach($ruleA->id, ['data' => json_encode($ruleA), 'order' => 0]));
        $reservations->last()->dynamicPricings()->attach($ruleB->id, ['data' => json_encode($ruleB), 'order' => 0]);

        $applications = (new Report(now()->toDateString(), now()->addWeek()->toDateString()))->dynamicPricingApplications()->keyBy('id');

        $this->assertSame('Rule A', $applications[$ruleA->id]['title']);
        $this->assertFalse($applications[$ruleA->id]['deleted']);
        $this->assertEquals(3, $applications[$ruleA->id]['reservations']);
        $this->assertEquals(0.75, $applications[$ruleA->id]['percentage']);

        $this->assertEquals(1, $applications[$ruleB->id]['reservations']);
        $this->assertEquals(0.25, $applications[$ruleB->id]['percentage']);
    }

    public function test_dynamic_pricing_applications_survive_a_deleted_rule()
    {
        $item = $this->makeStatamicItem();
        $rule = DynamicPricing::factory()->create(['title' => 'Deleted Rule']);
        $reservation = Reservation::factory(['item_id' => $item->id(), 'status' => 'confirmed'])->create();
        $reservation->dynamicPricings()->attach($rule->id, ['data' => json_encode($rule), 'order' => 0]);

        $ruleId = $rule->id;
        $rule->delete(); // DynamicPricing has no soft deletes — the rule row is gone, the pivot survives.

        $row = (new Report(now()->toDateString(), now()->addWeek()->toDateString()))->dynamicPricingApplications()->firstWhere('id', $ruleId);
        $this->assertNotNull($row);
        $this->assertTrue($row['deleted']);
        $this->assertSame('Deleted Rule', $row['title']);
        $this->assertEquals(1, $row['reservations']);
    }

    // A force-deleted affiliate must use a populated snapshot even when an earlier pre-snapshot
    // (null data) booking sorts first in the group.
    public function test_affiliate_sales_pick_a_populated_snapshot_for_a_deleted_affiliate()
    {
        $item = $this->makeStatamicItem();
        $affiliate = Affiliate::factory()->create(['name' => 'Snapshot Name', 'code' => 'SNAP', 'fee' => 20]);

        $legacy = Reservation::factory(['item_id' => $item->id(), 'status' => 'confirmed', 'total' => '100.00'])->create();
        $legacy->affiliate()->attach($affiliate->id, ['fee' => $affiliate->fee]); // data = null (pre-snapshot)

        $recent = Reservation::factory(['item_id' => $item->id(), 'status' => 'confirmed', 'total' => '200.00'])->create();
        $recent->affiliate()->attach($affiliate->id, [
            'fee' => $affiliate->fee,
            'data' => json_encode(['name' => $affiliate->name, 'code' => $affiliate->code]),
        ]);

        $affiliate->forceDelete();

        $row = (new Report(now()->toDateString(), now()->addWeek()->toDateString()))->affiliateSales()->firstWhere('id', $affiliate->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['deleted']);
        $this->assertSame('Snapshot Name', $row['title']);
        $this->assertEquals(2, $row['reservations']);
    }

    // A renamed-then-deleted rule must show the title from an application inside the selected range,
    // not one leaked from an out-of-range application.
    public function test_dynamic_pricing_snapshot_is_scoped_to_the_selected_reservations()
    {
        $item = $this->makeStatamicItem();
        $rule = DynamicPricing::factory()->create(['title' => 'New Name']);

        // Out-of-range application FIRST (carries a stale snapshot title).
        $outOfRange = Reservation::factory([
            'item_id' => $item->id(),
            'status' => 'confirmed',
            'date_start' => today()->subYear()->toIso8601String(),
            'date_end' => today()->subYear()->addDays(2)->toIso8601String(),
        ])->create();
        $outOfRange->dynamicPricings()->attach($rule->id, ['data' => json_encode(['title' => 'Old Name']), 'order' => 0]);

        // In-range application with the current snapshot title.
        $inRange = Reservation::factory(['item_id' => $item->id(), 'status' => 'confirmed'])->create();
        $inRange->dynamicPricings()->attach($rule->id, ['data' => json_encode(['title' => 'New Name']), 'order' => 0]);

        $ruleId = $rule->id;
        $rule->delete();

        $row = (new Report(now()->toDateString(), now()->addWeek()->toDateString()))->dynamicPricingApplications()->firstWhere('id', $ruleId);
        $this->assertNotNull($row);
        $this->assertSame('New Name', $row['title']);
        $this->assertEquals(1, $row['reservations']);
    }
}
