<?php

namespace Reach\StatamicResrv\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Keeps a page that rendered the `resrv_checkout_redirect` tag out of Statamic's static cache.
 *
 * The tag's output belongs to one visitor's session, and the static cache answers a later
 * request for the same URL without running the tag — so a cached checkout-complete page would
 * hand the booking shown on it to anyone who asks for `?payment_pending=<id>`.
 */
class MarkCheckoutCompleteUncacheable
{
    /** Request attribute the tag sets when it renders. */
    public const ATTRIBUTE = 'resrv.checkout_complete_rendered';

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($request->attributes->get(self::ATTRIBUTE)) {
            // Statamic's static caching middleware skips any response carrying this header.
            $response->headers->set('X-Statamic-Uncacheable', 'true');
        }

        return $response;
    }
}
