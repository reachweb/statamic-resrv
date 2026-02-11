<?php

namespace Reach\StatamicResrv\Jobs;

use Carbon\CarbonPeriod;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Reach\StatamicResrv\Models\Availability;

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

        $dataImport->prepare()->each(function ($item, $id) {
            $item->each(function ($data) use ($id) {
                $period = CarbonPeriod::create($data['date_start'], $data['date_end']);
                $rateId = $data['rate_id'] ?? null;
                $dataToAdd = [];
                foreach ($period as $day) {
                    $dayData = [
                        'statamic_id' => $id,
                        'date' => $day->isoFormat('YYYY-MM-DD'),
                        'price' => $data['price'],
                        'available' => $data['available'],
                    ];
                    if ($rateId) {
                        $dayData['rate_id'] = $rateId;
                    }
                    $dataToAdd[] = $dayData;
                }
                Availability::upsert($dataToAdd, ['statamic_id', 'date', 'rate_id'], ['price', 'available']);
            });
        });

        Cache::forget('resrv-data-import');

        return true;
    }
}
