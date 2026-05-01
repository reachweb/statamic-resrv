<?php

namespace Reach\StatamicResrv\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Reach\StatamicResrv\Models\Affiliate;

class SetResrvAffiliateCookie
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response|RedirectResponse)  $next
     * @return Response|RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->missing('afid') || ! $request->isMethod('get')) {
            return $next($request);
        }

        $afid = $request->get('afid');

        if ($affiliate = Affiliate::where('code', $afid)->first()) {
            return $next($request)->cookie('resrv_afid', $afid, $affiliate->cookie_duration * 24 * 60);
        }

        return $next($request);
    }
}
