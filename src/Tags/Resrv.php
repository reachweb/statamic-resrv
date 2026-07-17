<?php

namespace Reach\StatamicResrv\Tags;

use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Tags\Tags;

class Resrv extends Tags
{
    public function searchJson()
    {
        if (session()->missing('resrv_search')) {
            return json_encode([]);
        }

        // HTML-safe flags so the JSON is safe to embed directly in markup or attributes;
        // Antlers does not escape tag output, and default /-escaping alone is fragile.
        return json_encode(session()->get('resrv_search'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    public function reservationFromUri()
    {
        $validator = Validator::make(request()->all(), [
            'ref' => 'required|string|max:255',
            'hash' => 'required|string|size:64',
        ]);

        if ($validator->fails()) {
            throw new NotFoundHttpException;
        }

        $reservation = Reservation::findForCustomerLookup(
            request()->get('ref'),
            request()->get('hash'),
            $this->lookupStatuses(),
        );

        if (! $reservation) {
            throw new NotFoundHttpException;
        }

        return $reservation;
    }

    /**
     * Statuses a customer deep link may resolve to: live bookings (confirmed + partner) by
     * default, or an explicit pipe-separated `statuses` parameter for pages that need more.
     *
     * @return string[]
     */
    protected function lookupStatuses(): array
    {
        $statuses = $this->params->get('statuses');

        if (! $statuses) {
            return ReservationStatus::live();
        }

        return collect(explode('|', $statuses))
            ->map(fn (string $status) => trim($status))
            ->map(function (string $status) use ($statuses) {
                if ($status === '') {
                    throw new \Exception('Resrv Tag error: Empty status segment in statuses parameter: '.$statuses);
                }

                $case = ReservationStatus::tryFrom($status);

                if ($case === null) {
                    throw new \Exception('Resrv Tag error: Invalid reservation status: '.$status);
                }

                return $case->value;
            })
            ->values()
            ->all();
    }

    public function cutoff()
    {
        $entryId = $this->params->get('entry') ?? $this->context->get('id');
        $date = $this->params->get('date') ?? now()->format('Y-m-d');

        if (! $entryId) {
            return throw new \Exception('Resrv Tag error: No entry ID provided or could be found in context.');
        }

        try {
            $resrvEntry = ResrvEntry::whereItemId($entryId);
        } catch (\Exception $e) {
            return throw new \Exception('Resrv Tag error: Entry not found for ID: '.$entryId);
        }

        $schedule = $resrvEntry->getCutoffScheduleForDate($date);

        if (! $schedule) {
            return [
                'has_cutoff_rules' => false,
                'starting_time' => null,
                'cutoff_time' => null,
                'cutoff_hours' => null,
                'schedule_name' => null,
            ];
        }

        // Calculate cutoff time
        $reservationDate = Carbon::parse($date);
        $startingDateTime = Carbon::parse($reservationDate->format('Y-m-d').' '.$schedule['starting_time']);
        $cutoffDateTime = $startingDateTime->copy()->subHours($schedule['cutoff_hours']);

        return [
            'has_cutoff_rules' => true,
            'starting_time' => $schedule['starting_time'],
            'cutoff_time' => $cutoffDateTime->format('H:i'),
            'cutoff_datetime' => $cutoffDateTime->toISOString(),
            'cutoff_hours' => $schedule['cutoff_hours'],
            'schedule_name' => $schedule['schedule_name'],
            'is_past_cutoff' => Carbon::now()->greaterThan($cutoffDateTime),
        ];
    }
}
