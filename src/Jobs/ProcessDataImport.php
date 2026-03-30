<?php

namespace Reach\StatamicResrv\Jobs;

use Carbon\CarbonPeriod;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Repositories\AvailabilityRepository;

class ProcessDataImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $dataImport = Cache::get('resrv-data-import');

        if (! $dataImport) {
            Log::warning('Data import job fired but cache entry was missing.');

            return;
        }

        $dataImport->prepare()->each(function ($item, $id) {
            $defaultRateId = null;

            $item->each(function ($data) use ($id, &$defaultRateId) {
                $period = CarbonPeriod::create($data['date_start'], $data['date_end']);
                $rateId = $data['rate_id'] ?? null;

                if ($rateId) {
                    $rateId = app(AvailabilityRepository::class)->resolveBaseRateId($rateId);
                }

                if (! $rateId) {
                    $rateCount = Rate::forEntry($id)->count();

                    if ($rateCount > 1) {
                        Log::warning("Data import: skipping row for entry {$id} — rate_id required when multiple rates exist.");

                        return;
                    }

                    if ($defaultRateId === null) {
                        $defaultRateId = Rate::forEntry($id)->value('id');
                    }
                    if ($defaultRateId === null) {
                        $defaultRate = Rate::findOrCreateDefaultForEntry($id);
                        $defaultRateId = $defaultRate?->id;
                    }
                    $rateId = $defaultRateId;
                }

                if (! $rateId) {
                    return;
                }

                $dataToAdd = [];
                foreach ($period as $day) {
                    $dataToAdd[] = [
                        'statamic_id' => $id,
                        'date' => $day->isoFormat('YYYY-MM-DD'),
                        'price' => $data['price'],
                        'available' => $data['available'],
                        'rate_id' => $rateId,
                    ];
                }
                Availability::upsert($dataToAdd, ['statamic_id', 'date', 'rate_id'], ['price', 'available']);
            });
        });

        Cache::forget('resrv-data-import');

        return true;
    }
}
