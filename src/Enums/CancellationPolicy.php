<?php

namespace Reach\StatamicResrv\Enums;

use Carbon\Carbon;

enum CancellationPolicy: string
{
    case FreeCancellation = 'free_cancellation';
    case NonRefundable = 'non_refundable';

    /**
     * The site-wide policy that rates and reservations without an explicit
     * policy inherit, driven by the global free_cancellation_period setting.
     * An unset (zero) setting yields a NULL period — "nothing configured" —
     * which is distinct from a rate's explicit zero-day policy (free
     * cancellation right up to check-in).
     *
     * @return array{policy: self, period: ?int}
     */
    public static function globalDefault(): array
    {
        $period = (int) config('resrv-config.free_cancellation_period');

        return [
            'policy' => self::FreeCancellation,
            'period' => $period > 0 ? $period : null,
        ];
    }

    /**
     * Customer-facing label for a resolved policy. Returns null when there is nothing
     * meaningful to advertise (a NULL period from the untouched global default), so sites
     * that never configured cancellation don't suddenly grow a policy line. An explicit
     * zero-day period still labels — free cancellation until the check-in date itself.
     */
    public static function labelFor(self|string|null $policy, ?int $period, Carbon $dateStart): ?string
    {
        if (is_string($policy)) {
            $policy = self::tryFrom($policy);
        }

        if ($policy === self::NonRefundable) {
            return trans('statamic-resrv::frontend.nonRefundable');
        }

        if ($period === null) {
            return null;
        }

        return trans('statamic-resrv::frontend.freeCancellationUntilDate', [
            'date' => $dateStart->copy()->subDays($period)->format('D d M Y'),
        ]);
    }
}
