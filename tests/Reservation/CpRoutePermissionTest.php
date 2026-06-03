<?php

namespace Reach\StatamicResrv\Tests\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Reach\StatamicResrv\Tests\TestCase;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Support\Str;

class CpRoutePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The guard throws AuthorizationException; exception handling must be on to get an HTTP 403.
        $this->withExceptionHandling();
    }

    private function actingAsUserWithPermissions(array $permissions): void
    {
        $role = Role::make('role_'.Str::random(8))->addPermission($permissions)->save();

        $user = User::make()
            ->id('user-'.Str::random(8))
            ->email(Str::random(8).'@test.com')
            ->assignRole($role);

        $this->actingAs($user);
    }

    public function test_cp_user_without_use_resrv_permission_is_forbidden_from_refunding()
    {
        $this->actingAsUserWithPermissions(['access cp']);

        $this->patchJson(cp_route('resrv.reservation.refund'), ['id' => 1])
            ->assertForbidden();
    }

    public function test_cp_user_without_use_resrv_permission_is_forbidden_from_editing_availability()
    {
        $this->actingAsUserWithPermissions(['access cp']);

        $this->postJson(cp_route('resrv.availability.update'), [])
            ->assertForbidden();
    }

    public function test_cp_user_without_use_resrv_permission_is_forbidden_from_listing_reservations()
    {
        $this->actingAsUserWithPermissions(['access cp']);

        $this->getJson(cp_route('resrv.reservation.index'))
            ->assertForbidden();
    }

    public function test_cp_user_without_use_resrv_permission_is_forbidden_from_clearing_stuck_pending()
    {
        $this->actingAsUserWithPermissions(['access cp']);

        $this->postJson(cp_route('resrv.availability.clearStuckPending'), [])
            ->assertForbidden();
    }

    public function test_cp_user_without_use_resrv_permission_is_forbidden_from_creating_an_affiliate()
    {
        $this->actingAsUserWithPermissions(['access cp']);

        $this->postJson(cp_route('resrv.affiliate.create'), [])
            ->assertForbidden();
    }

    public function test_cp_user_without_use_resrv_permission_is_forbidden_from_exporting()
    {
        $this->actingAsUserWithPermissions(['access cp']);

        $this->getJson(cp_route('resrv.export.count').'?start=2026-01-01&end=2026-01-31')
            ->assertForbidden();
    }

    public function test_cp_user_with_use_resrv_permission_can_list_reservations()
    {
        $this->actingAsUserWithPermissions(['access cp', 'use resrv']);

        $this->getJson(cp_route('resrv.reservation.index'))
            ->assertOk();
    }

    public function test_cp_user_with_use_resrv_permission_can_reach_the_export_page()
    {
        $this->actingAsUserWithPermissions(['access cp', 'use resrv']);

        $this->get(cp_route('resrv.export.index'))
            ->assertOk();
    }

    public function test_super_user_can_list_reservations()
    {
        $this->signInAdmin();

        $this->getJson(cp_route('resrv.reservation.index'))
            ->assertOk();
    }
}
