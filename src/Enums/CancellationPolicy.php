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
     *
     * @return array{policy: self, period: ?int}
     */
    public static function globalDefault(): array
    {
        return [
            'policy' => self::FreeCancellation,
            'period' => (int) config('resrv-config.free_cancellation_period'),
        ];
    }

    /**
     * Customer-facing label for a resolved policy. Returns null when there is nothing
     * meaningful to advertise (the untouched zero-period global default), so sites that
     * never configured cancellation don't suddenly grow a policy line.
     */
    public static function labelFor(self|string|null $policy, ?int $period, Carbon $dateStart): ?string
    {
        if (is_string($policy)) {
            $policy = self::tryFrom($policy);
        }

        if ($policy === self::NonRefundable) {
            return trans('statamic-resrv::frontend.nonRefundable');
        }

        if (! $period) {
            return null;
        }

        return trans('statamic-resrv::frontend.freeCancellationUntilDate', [
            'date' => $dateStart->copy()->subDays($period)->format('D d M Y'),
        ]);
    }
}
