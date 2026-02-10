<?php

namespace Reach\StatamicResrv\Tags;

use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Tags\Tags;

class Resrv extends Tags
{
    public function searchJson()
    {
        if (session()->missing('resrv_search')) {
            return json_encode([]);
        }

        return json_encode(session()->get('resrv_search'));
    }

    public function reservationFromUri()
    {
        $validator = Validator::make(request()->all(), [
            'ref' => 'required|string|max:10',
            'hash' => 'required|string|size:64',
        ]);

        if ($validator->fails()) {
            abort(400, 'Invalid reservation parameters.');
        }

        $reservation = Reservation::where('reference', request()->get('ref'))
            ->where('status', ReservationStatus::CONFIRMED)
            ->firstOrFail();

        $expectedHash = hash_hmac('sha256', $reservation->customer->email, config('app.key'));

        if (! hash_equals($expectedHash, request()->get('hash'))) {
            abort(404);
        }

        return $reservation;
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
