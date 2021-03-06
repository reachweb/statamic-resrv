<?php

namespace Reach\StatamicResrv\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Reach\StatamicResrv\Jobs\SaveSearchToSession;

class SetResrvSearchByVariables
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->missing('date_start')) {
            return $next($request);
        }

        $data = collect($request->only(['date_start', 'date_end', 'duration', 'quantity', 'advanced']));

        if ($data->has('duration')) {
            $data->put('date_end', Carbon::parse($data->get('date_start'))->addDays($data->get('duration'))->toDateString());
            $data->forget('duration');
        }

        SaveSearchToSession::dispatchSync($data->toArray());

        return $next($request);
    }
}
