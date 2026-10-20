<?php

namespace Reach\StatamicResrv\Traits;

use Illuminate\Support\Facades\Log;

/**
 * For email jobs dispatched after the response. Laravel runs every deferred job in one terminate
 * loop where the first throw skips all the rest, so a lapse sweep cancelling many holds would drop
 * every later notice on a single transport failure — with no retry, as those rows are already
 * terminal. Inline (sync) runs log the failure and let the next job run; on a real queue the
 * exception still propagates so the worker's retries apply.
 */
trait IsolatesDeferredSendFailures
{
    public function middleware(): array
    {
        return [function ($job, $next) {
            try {
                return $next($job);
            } catch (\Throwable $e) {
                if ($this->job?->getConnectionName() !== 'sync') {
                    throw $e;
                }

                Log::error('A reservation email could not be sent and will not be retried.', [
                    'reservation_id' => $this->reservation?->id,
                    'job' => static::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }];
    }
}
