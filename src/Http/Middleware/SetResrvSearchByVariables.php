<?php

namespace Reach\StatamicResrv\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Reach\StatamicResrv\Events\AvailabilitySearch;

class SetResrvSearchByVariables
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->missing('date_start') || ! $request->isMethod('get')) {
            return $next($request);
        }

        $data = collect($request->only(['date_start', 'date_end', 'duration', 'quantity', 'advanced']));

        if ($data->has('duration')) {
            $data->put('date_end', Carbon::parse($data->get('date_start'))->addDays($data->get('duration'))->toDateString());
            $data->forget('duration');
        }

        AvailabilitySearch::dispatch($data->toArray());

        return $next($request);
    }
}
