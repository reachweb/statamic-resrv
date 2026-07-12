<?php

namespace Reach\StatamicResrv\Tests\Utilities;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Reach\StatamicResrv\Tests\TestCase;

class CpRouteConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        // Sites can relocate the CP away from /cp (CP_ROUTE env). The route
        // prefix must be applied before providers register routes, hence this
        // hook rather than a post-boot Config::set.
        $app['config']->set('statamic.cp.route', 'backoffice');
    }

    public function test_every_resrv_cp_route_is_registered_under_the_configured_cp_prefix()
    {
        $resrvRoutes = collect(Route::getRoutes()->getRoutesByName())
            ->filter(fn ($route, $name) => str_starts_with($name, 'statamic.cp.resrv.'));

        $this->assertNotEmpty($resrvRoutes);

        foreach ($resrvRoutes as $name => $route) {
            $this->assertStringStartsWith('backoffice/resrv', $route->uri(), "Route {$name} ignores the configured CP route");
        }
    }

    public function test_resrv_cp_pages_resolve_under_a_custom_cp_route()
    {
        $this->signInAdmin();

        $this->assertStringContainsString('/backoffice/resrv/reservations', cp_route('resrv.reservations.index'));

        $this->get(cp_route('resrv.reservations.index'))->assertOk();
        $this->getJson(cp_route('resrv.utilities.entries'))->assertOk();
    }
}
