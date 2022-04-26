<?php

namespace Reach\StatamicResrv\Jobs;

use Carbon\CarbonPeriod;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Reach\StatamicResrv\Models\AdvancedAvailability;
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
                $advanced = array_key_exists('advanced', $data) ? $data['advanced'] : false;
                $dataToAdd = [];
                foreach ($period as $day) {
                    $dayData = [
                        'statamic_id' => $id,
                        'date' => $day->isoFormat('YYYY-MM-DD'),
                        'price' => $data['price'],
                        'available' => $data['available'],
                    ];
                    if ($advanced) {
                        $dayData['property'] = $data['advanced'];
                    }
                    $dataToAdd[] = $dayData;
                }
                if ($advanced) {
                    AdvancedAvailability::upsert($dataToAdd, ['statamic_id', 'date', 'property'], ['price', 'available']);
                } else {
                    Availability::upsert($dataToAdd, ['statamic_id', 'date'], ['price', 'available']);
                }
            });
        });

        Cache::forget('resrv-data-import');

        return true;
    }
}
