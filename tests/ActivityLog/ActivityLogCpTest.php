<?php

namespace Reach\StatamicResrv\Tests\ActivityLog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str as SupportStr;
use Inertia\Testing\AssertableInertia as Assert;
use Reach\StatamicResrv\Models\AvailabilityChange;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\ReservationLog;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Support\Str;

class ActivityLogCpTest extends TestCase
{
    use RefreshDatabase;

    private function makeAvailabilityChange(array $overrides = []): AvailabilityChange
    {
        return AvailabilityChange::create(array_merge([
            'batch' => (string) SupportStr::uuid(),
            'statamic_id' => 'entry-id',
            'rate_id' => null,
            'date' => today()->toDateString(),
            'action' => 'update',
            'field' => 'available',
            'old_value' => 2,
            'new_value' => 1,
            'reason' => 'cp_edit',
        ], $overrides));
    }

    private function makeReservationLog(array $overrides = []): ReservationLog
    {
        return ReservationLog::create(array_merge([
            'reservation_id' => 1,
            'reference' => 'ABCDEF',
            'status_from' => null,
            'status_to' => 'pending',
            'reason' => 'checkout_started',
        ], $overrides));
    }

    public function test_the_index_page_renders_with_the_enabled_flag()
    {
        $this->signInAdmin();

        Config::set('resrv-config.enable_activity_log', true);

        $this->get(cp_route('resrv.logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('resrv::ActivityLog/Index')
                ->where('enabled', true)
                ->has('availabilityReasons')
                ->has('reservationReasons')
            );
    }

    public function test_the_index_page_reports_when_logging_is_disabled()
    {
        $this->signInAdmin();

        $this->get(cp_route('resrv.logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('enabled', false));
    }

    public function test_users_without_the_resrv_permission_are_forbidden()
    {
        $this->withExceptionHandling();

        $role = Role::make('role_'.Str::random(8))->addPermission(['access cp'])->save();
        $user = User::make()
            ->id('user-'.Str::random(8))
            ->email(Str::random(8).'@test.com')
            ->assignRole($role);
        $this->actingAs($user);

        $this->getJson(cp_route('resrv.logs.index'))->assertForbidden();
        $this->getJson(cp_route('resrv.logs.availability'))->assertForbidden();
        $this->getJson(cp_route('resrv.logs.reservations'))->assertForbidden();
    }

    public function test_availability_changes_are_paginated_and_explicitly_ordered()
    {
        $this->signInAdmin();

        $this->travelTo(now()->subMinutes(10));
        $old = $this->makeAvailabilityChange();
        $this->travelBack();
        $recent = $this->makeAvailabilityChange();

        $response = $this->getJson(cp_route('resrv.logs.availability'))->assertOk();

        $this->assertEquals(2, $response->json('total'));
        $this->assertEquals($recent->id, $response->json('data.0.id'));
        $this->assertEquals($old->id, $response->json('data.1.id'));
    }

    public function test_availability_rows_are_enriched_with_titles_and_labels()
    {
        $this->signInAdmin();

        $item = $this->makeStatamicItem();
        $rate = Rate::factory()->create(['collection' => 'pages']);

        $this->makeAvailabilityChange([
            'statamic_id' => $item->id(),
            'rate_id' => $rate->id,
        ]);
        // A change pointing at a purged entry and rate must still render its snapshots.
        $this->makeAvailabilityChange(['statamic_id' => 'gone-entry', 'rate_id' => 999]);

        $response = $this->getJson(cp_route('resrv.logs.availability'))->assertOk();

        $rows = collect($response->json('data'))->keyBy('statamic_id');

        $this->assertEquals($item->title, $rows[$item->id()]['entry_title']);
        $this->assertEquals($rate->title, $rows[$item->id()]['rate_title']);
        $this->assertEquals('Edited in the Control Panel', $rows[$item->id()]['reason_label']);

        $this->assertEquals('gone-entry', $rows['gone-entry']['entry_title']);
        $this->assertEquals('#999', $rows['gone-entry']['rate_title']);
    }

    public function test_availability_changes_can_be_filtered()
    {
        $this->signInAdmin();

        $batch = (string) SupportStr::uuid();
        $this->makeAvailabilityChange(['statamic_id' => 'entry-a', 'batch' => $batch]);
        $this->makeAvailabilityChange(['statamic_id' => 'entry-b', 'reason' => 'import']);
        $this->makeAvailabilityChange(['statamic_id' => 'entry-b', 'date' => today()->addDays(5)->toDateString()]);

        $this->assertEquals(1, $this->getJson(cp_route('resrv.logs.availability', ['statamic_id' => 'entry-a']))->json('total'));
        $this->assertEquals(1, $this->getJson(cp_route('resrv.logs.availability', ['reason' => 'import']))->json('total'));
        $this->assertEquals(1, $this->getJson(cp_route('resrv.logs.availability', ['batch' => $batch]))->json('total'));
        $this->assertEquals(1, $this->getJson(cp_route('resrv.logs.availability', [
            'date_start' => today()->addDays(2)->toDateString(),
            'date_end' => today()->addDays(6)->toDateString(),
        ]))->json('total'));

        $this->withExceptionHandling()
            ->getJson(cp_route('resrv.logs.availability', ['reason' => 'not-a-reason']))
            ->assertStatus(422);
    }

    public function test_reservation_logs_are_paginated_filtered_and_labelled()
    {
        $this->signInAdmin();

        $this->makeReservationLog(['reference' => 'AAA-111']);
        $this->makeReservationLog([
            'reservation_id' => 2,
            'reference' => 'BBB-222',
            'status_from' => 'pending',
            'status_to' => 'confirmed',
            'reason' => 'webhook_confirmed',
            'context' => ['gateway' => 'stripe'],
        ]);

        $response = $this->getJson(cp_route('resrv.logs.reservations'))->assertOk();
        $this->assertEquals(2, $response->json('total'));

        $filtered = $this->getJson(cp_route('resrv.logs.reservations', ['reference' => 'BBB']))->assertOk();
        $this->assertEquals(1, $filtered->json('total'));
        $this->assertEquals('Confirmed by payment webhook', $filtered->json('data.0.reason_label'));
        $this->assertEquals(['gateway' => 'stripe'], $filtered->json('data.0.context'));

        $this->assertEquals(1, $this->getJson(cp_route('resrv.logs.reservations', ['reservation_id' => 2]))->json('total'));
        $this->assertEquals(1, $this->getJson(cp_route('resrv.logs.reservations', ['reason' => 'checkout_started']))->json('total'));
    }

    public function test_per_page_is_clamped()
    {
        $this->signInAdmin();

        foreach (range(1, 3) as $i) {
            $this->makeAvailabilityChange();
        }

        $response = $this->getJson(cp_route('resrv.logs.availability', ['perPage' => 2]))->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(2, $response->json('last_page'));

        $huge = $this->getJson(cp_route('resrv.logs.availability', ['perPage' => 5000]))->assertOk();
        $this->assertEquals(100, $huge->json('per_page'));
    }
}
