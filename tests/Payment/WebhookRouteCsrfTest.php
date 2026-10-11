<?php

namespace Reach\StatamicResrv\Tests\Payment;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Reach\StatamicResrv\Tests\TestCase;

class WebhookRouteCsrfTest extends TestCase
{
    // Laravel 13's web group runs PreventRequestForgery, Laravel 12's runs ValidateCsrfToken.
    private const CSRF_MIDDLEWARE = [
        PreventRequestForgery::class,
        ValidateCsrfToken::class,
        VerifyCsrfToken::class,
    ];

    // Laravel skips the CSRF check while running unit tests, so posting to a webhook can't catch a
    // missing exclusion. Resolve each route's middleware stack the way the router does instead.
    public function test_webhook_routes_are_excluded_from_csrf_protection()
    {
        $router = app('router');

        foreach ([
            'resrv.webhook.index',
            'resrv.webhook.store',
            'resrv.webhook.gateway.index',
            'resrv.webhook.gateway.store',
        ] as $name) {
            $route = $router->getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route [{$name}] is not registered.");

            // Without its exclusions the route must run CSRF middleware, otherwise the check below proves nothing.
            $this->assertNotEmpty(
                array_intersect(self::CSRF_MIDDLEWARE, $router->resolveMiddleware($route->gatherMiddleware())),
                "Route [{$name}] is not in a group that runs CSRF middleware."
            );

            $this->assertSame(
                [],
                array_values(array_intersect(self::CSRF_MIDDLEWARE, $router->gatherRouteMiddleware($route))),
                "Route [{$name}] still runs CSRF middleware."
            );
        }
    }
}
