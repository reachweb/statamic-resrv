<?php

namespace Reach\StatamicResrv\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Enums\AvailabilityChangeReason;
use Reach\StatamicResrv\Enums\ReservationLogReason;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\AvailabilityChange;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Models\ReservationLog;
use Statamic\Facades\User;
use Throwable;

/**
 * Writes the owner-facing activity log (availability changes and reservation
 * lifecycle events). Every write is a no-op when the enable_activity_log
 * setting is off, is deferred until the surrounding transaction commits, and
 * is wrapped in try/catch — a log failure must never block a booking, refund,
 * or CP edit.
 */
class ActivityLog
{
    public function enabled(): bool
    {
        return config('resrv-config.enable_activity_log') === true;
    }

    /**
     * Bulk-log a batch of availability changes. Each change is an array with
     * keys: statamic_id, rate_id, date, action (create|update|delete),
     * field (available|price), old_value, new_value. No-op updates
     * (old == new) are skipped. All rows share one batch uuid.
     *
     * @param  array<int, array{statamic_id: string, rate_id: int|null, date: mixed, action: string, field: string, old_value: mixed, new_value: mixed}>  $changes
     * @param  array{id: string|int|null, name: string|null}|null  $actor
     */
    public function logAvailabilityChanges(
        AvailabilityChangeReason $reason,
        array $changes,
        ?int $reservationId = null,
        ?array $actor = null,
        ?string $batch = null,
    ): void {
        if (! $this->enabled() || $changes === []) {
            return;
        }

        try {
            $batch ??= (string) Str::uuid();
            $now = now();

            $rows = collect($changes)
                ->reject(fn (array $change) => ($change['action'] ?? 'update') === 'update'
                    && $this->normalizeValue($change['old_value'] ?? null) === $this->normalizeValue($change['new_value'] ?? null))
                ->map(fn (array $change) => [
                    'batch' => $batch,
                    'statamic_id' => $change['statamic_id'],
                    'rate_id' => $change['rate_id'] ?? null,
                    'date' => $this->normalizeDate($change['date']),
                    'action' => $change['action'],
                    'field' => $change['field'],
                    'old_value' => $this->normalizeValue($change['old_value'] ?? null),
                    'new_value' => $this->normalizeValue($change['new_value'] ?? null),
                    'reason' => $reason->value,
                    'reservation_id' => $reservationId,
                    'actor_id' => $actor['id'] ?? null,
                    'actor_name' => $actor['name'] ?? null,
                    'created_at' => $now,
                ])
                ->all();

            if ($rows !== []) {
                // Chunked so a large batch (13 bind params per row) stays below the driver
                // placeholder limits — SQLite caps at 32,766, so ~2,500 rows in one insert
                // (e.g. a mass edit of several rates over a year) would silently lose the
                // whole batch. The shared batch uuid is on every row, so chunks regroup.
                $this->persistAfterCommit(
                    function () use ($rows) {
                        foreach (array_chunk($rows, 1000) as $chunk) {
                            AvailabilityChange::insert($chunk);
                        }
                    },
                    ['type' => 'availability', 'reason' => $reason->value],
                );
            }
        } catch (Throwable $e) {
            Log::error('Resrv activity log write failed', [
                'type' => 'availability',
                'reason' => $reason->value,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array{id: string|int|null, name: string|null}|null  $actor
     */
    public function logReservation(
        Reservation $reservation,
        ?ReservationStatus $from,
        ReservationStatus $to,
        ReservationLogReason $reason,
        array $context = [],
        ?array $actor = null,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        try {
            $attributes = [
                'reservation_id' => $reservation->id,
                'reference' => (string) $reservation->reference,
                'status_from' => $from?->value,
                'status_to' => $to->value,
                'reason' => $reason->value,
                'context' => $context === [] ? null : $context,
                'actor_id' => $actor['id'] ?? null,
                'actor_name' => $actor['name'] ?? null,
            ];

            $this->persistAfterCommit(
                fn () => ReservationLog::create($attributes),
                ['type' => 'reservation', 'reservation_id' => $reservation->id, 'reason' => $reason->value],
            );
        } catch (Throwable $e) {
            Log::error('Resrv activity log write failed', [
                'type' => 'reservation',
                'reservation_id' => $reservation->id,
                'reason' => $reason->value,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run the write once the surrounding transaction (if any) commits; outside a
     * transaction it runs immediately. PostgreSQL aborts the whole transaction on any
     * failed statement, so an in-transaction insert failure (e.g. during the checkout
     * transaction that dispatches ReservationCreated) would sink the booking despite
     * the catch. Deferring also drops the log rows when the business operation rolls
     * back — a change that never committed must not be logged.
     */
    private function persistAfterCommit(callable $write, array $logContext): void
    {
        DB::afterCommit(function () use ($write, $logContext) {
            try {
                $write();
            } catch (Throwable $e) {
                Log::error('Resrv activity log write failed', [
                    ...$logContext,
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * The current CP user as an actor snapshot, or null outside an
     * authenticated CP context (customer flows, queued jobs, scheduler).
     *
     * @return array{id: string, name: string}|null
     */
    public function cpActor(): ?array
    {
        $user = User::current();

        if (! $user) {
            return null;
        }

        return [
            'id' => (string) $user->id(),
            'name' => $user->name() ?: (string) $user->email(),
        ];
    }

    private function normalizeValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    private function normalizeDate(mixed $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return substr((string) $date, 0, 10);
    }
}
