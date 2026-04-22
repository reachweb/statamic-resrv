<?php

namespace Reach\StatamicResrv\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Reach\StatamicResrv\Events\AvailabilitySearch;

class SetResrvSearchByVariables
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response|RedirectResponse)  $next
     * @return Response|RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->missing('date_start') || ! $request->isMethod('get')) {
            return $next($request);
        }

        $data = collect($request->only(['date_start', 'date_end', 'duration', 'quantity', 'advanced']));

        if ($data->has('duration')) {
            $data->put('date_end', Carbon::parse($data->get('date_start'))->addDays((int) $data->get('duration'))->toDateString());
            $data->forget('duration');
        }

        AvailabilitySearch::dispatch($data->toArray());

        return $next($request);
    }
}
