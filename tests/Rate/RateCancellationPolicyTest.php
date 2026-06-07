<?php

namespace Reach\StatamicResrv\Tests\Rate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Enums\CancellationPolicy;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Tests\TestCase;

class RateCancellationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_resolves_non_refundable_policy()
    {
        $rate = Rate::factory()->nonRefundable()->create();

        $cancellation = $rate->effectiveCancellationPolicy();

        $this->assertEquals(CancellationPolicy::NonRefundable, $cancellation['policy']);
        $this->assertNull($cancellation['period']);
        $this->assertTrue($rate->isNonRefundable());
    }

    public function test_rate_resolves_free_cancellation_policy_with_period()
    {
        $rate = Rate::factory()->freeCancellation(7)->create();

        $cancellation = $rate->effectiveCancellationPolicy();

        $this->assertEquals(CancellationPolicy::FreeCancellation, $cancellation['policy']);
        $this->assertEquals(7, $cancellation['period']);
        $this->assertFalse($rate->isNonRefundable());
    }

    public function test_rate_without_policy_inherits_global_config()
    {
        Config::set('resrv-config.free_cancellation_period', 5);

        $rate = Rate::factory()->create();

        $cancellation = $rate->effectiveCancellationPolicy();

        $this->assertEquals(CancellationPolicy::FreeCancellation, $cancellation['policy']);
        $this->assertEquals(5, $cancellation['period']);
    }

    public function test_rate_without_policy_inherits_global_config_even_when_unset()
    {
        Config::set('resrv-config.free_cancellation_period', 0);

        $rate = Rate::factory()->create();

        $cancellation = $rate->effectiveCancellationPolicy();

        $this->assertEquals(CancellationPolicy::FreeCancellation, $cancellation['policy']);
        $this->assertEquals(0, $cancellation['period']);
    }

    public function test_rate_policy_overrides_global_config()
    {
        Config::set('resrv-config.free_cancellation_period', 5);

        $rate = Rate::factory()->freeCancellation(2)->create();

        $this->assertEquals(2, $rate->effectiveCancellationPolicy()['period']);
    }

    public function test_free_cancellation_rate_with_missing_period_inherits_the_global_period()
    {
        Config::set('resrv-config.free_cancellation_period', 14);

        $rate = Rate::factory()->create([
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => null,
        ]);

        // A blind int cast would silently turn "inherit" into "0 days".
        $this->assertEquals(14, $rate->effectiveCancellationPolicy()['period']);
    }

    public function test_strictest_cancellation_policy_prefers_non_refundable()
    {
        $strictest = Reservation::strictestCancellationPolicy(collect([
            ['policy' => CancellationPolicy::FreeCancellation, 'period' => 10],
            ['policy' => CancellationPolicy::NonRefundable, 'period' => null],
            ['policy' => CancellationPolicy::FreeCancellation, 'period' => 2],
        ]));

        $this->assertEquals(CancellationPolicy::NonRefundable, $strictest['policy']);
    }

    public function test_strictest_cancellation_policy_picks_largest_period()
    {
        $strictest = Reservation::strictestCancellationPolicy(collect([
            ['policy' => CancellationPolicy::FreeCancellation, 'period' => 3],
            ['policy' => CancellationPolicy::FreeCancellation, 'period' => 10],
            ['policy' => CancellationPolicy::FreeCancellation, 'period' => 2],
        ]));

        $this->assertEquals(CancellationPolicy::FreeCancellation, $strictest['policy']);
        $this->assertEquals(10, $strictest['period']);
    }

    public function test_strictest_cancellation_policy_falls_back_to_global_for_missing_policies()
    {
        Config::set('resrv-config.free_cancellation_period', 4);

        $strictest = Reservation::strictestCancellationPolicy(collect([
            null,
            ['policy' => CancellationPolicy::FreeCancellation, 'period' => 2],
        ]));

        $this->assertEquals(4, $strictest['period']);
    }

    public function test_reservation_prefers_the_snapshot_over_the_live_rate()
    {
        $rate = Rate::factory()->nonRefundable()->create();

        $reservation = Reservation::factory()->create([
            'rate_id' => $rate->id,
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => 7,
        ]);

        $cancellation = $reservation->effectiveCancellationPolicy();

        $this->assertEquals(CancellationPolicy::FreeCancellation, $cancellation['policy']);
        $this->assertEquals(7, $cancellation['period']);
    }

    public function test_reservation_snapshot_is_immune_to_rate_and_config_edits()
    {
        $rate = Rate::factory()->freeCancellation(7)->create();

        $reservation = Reservation::factory()->create([
            'rate_id' => $rate->id,
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => 7,
        ]);

        $rate->update(['cancellation_policy' => 'non_refundable', 'free_cancellation_period' => null]);
        Config::set('resrv-config.free_cancellation_period', 30);

        $cancellation = $reservation->fresh()->effectiveCancellationPolicy();

        $this->assertEquals(CancellationPolicy::FreeCancellation, $cancellation['policy']);
        $this->assertEquals(7, $cancellation['period']);
    }

    public function test_legacy_reservation_without_snapshot_falls_back_to_the_live_rate()
    {
        $rate = Rate::factory()->nonRefundable()->create();

        $reservation = Reservation::factory()->create([
            'rate_id' => $rate->id,
            'cancellation_policy' => null,
            'free_cancellation_period' => null,
        ]);

        $this->assertEquals(CancellationPolicy::NonRefundable, $reservation->effectiveCancellationPolicy()['policy']);
    }

    public function test_legacy_reservation_without_snapshot_or_rate_falls_back_to_global()
    {
        Config::set('resrv-config.free_cancellation_period', 3);

        $reservation = Reservation::factory()->create([
            'cancellation_policy' => null,
            'free_cancellation_period' => null,
        ]);

        $cancellation = $reservation->effectiveCancellationPolicy();

        $this->assertEquals(CancellationPolicy::FreeCancellation, $cancellation['policy']);
        $this->assertEquals(3, $cancellation['period']);
    }

    public function test_legacy_parent_reservation_falls_back_to_strictest_child_policy()
    {
        $flexibleRate = Rate::factory()->freeCancellation(2)->create();
        $strictRate = Rate::factory()->nonRefundable()->create(['slug' => 'strict-rate']);

        $reservation = Reservation::factory()->create([
            'type' => 'parent',
            'cancellation_policy' => null,
            'free_cancellation_period' => null,
        ]);

        ChildReservation::factory()->create(['reservation_id' => $reservation->id, 'rate_id' => $flexibleRate->id]);
        ChildReservation::factory()->create(['reservation_id' => $reservation->id, 'rate_id' => $strictRate->id]);

        $this->assertEquals(CancellationPolicy::NonRefundable, $reservation->effectiveCancellationPolicy()['policy']);
    }

    public function test_cancellation_policy_label()
    {
        $nonRefundable = Reservation::factory()->create([
            'cancellation_policy' => 'non_refundable',
            'free_cancellation_period' => null,
        ]);

        $this->assertEquals(trans('statamic-resrv::frontend.nonRefundable'), $nonRefundable->cancellationPolicyLabel());

        $flexible = Reservation::factory()->create([
            'date_start' => today()->addDays(10)->toIso8601String(),
            'date_end' => today()->addDays(12)->toIso8601String(),
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => 7,
        ]);

        $this->assertEquals(
            trans('statamic-resrv::frontend.freeCancellationUntilDate', ['date' => today()->addDays(3)->format('D d M Y')]),
            $flexible->cancellationPolicyLabel()
        );
    }

    public function test_cancellation_policy_label_is_hidden_for_the_untouched_global_default()
    {
        Config::set('resrv-config.free_cancellation_period', 0);

        $reservation = Reservation::factory()->create([
            'cancellation_policy' => 'free_cancellation',
            'free_cancellation_period' => 0,
        ]);

        $this->assertNull($reservation->cancellationPolicyLabel());
    }

    public function test_migration_maps_legacy_refundable_flag_and_normalizes_zero_windows()
    {
        $migration = include __DIR__.'/../../database/migrations/2026_06_06_000000_add_cancellation_policy_to_rates.php';
        $migration->down();

        DB::table('resrv_rates')->insert([
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Legacy Non-Refundable',
            'slug' => 'legacy-non-refundable',
            'refundable' => false,
            'min_days_before' => 0,
            'max_days_before' => 0,
            'published' => true,
        ]);

        DB::table('resrv_rates')->insert([
            'collection' => 'pages',
            'apply_to_all' => true,
            'title' => 'Legacy Refundable',
            'slug' => 'legacy-refundable',
            'refundable' => true,
            'min_days_before' => 7,
            'published' => true,
        ]);

        $migration->up();

        $this->assertDatabaseHas('resrv_rates', [
            'slug' => 'legacy-non-refundable',
            'cancellation_policy' => 'non_refundable',
            'min_days_before' => null,
            'max_days_before' => null,
        ]);

        $this->assertDatabaseHas('resrv_rates', [
            'slug' => 'legacy-refundable',
            'cancellation_policy' => null,
            'min_days_before' => 7,
        ]);
    }
}
