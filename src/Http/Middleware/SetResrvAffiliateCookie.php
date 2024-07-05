<?php

namespace Reach\StatamicResrv\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Reach\StatamicResrv\Models\Affiliate;

class SetResrvAffiliateCookie
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->missing('afid') || ! $request->isMethod('get')) {
            return $next($request);
        }

        $afid = $request->get('afid');

        if ($affiliate = Affiliate::where('code', $afid)->first()) {
            return $next($request)->cookie('resrv_afid', $afid, ($affiliate->cookie_duration * 24 * 60));
        }

        return $next($request);
    }
}
