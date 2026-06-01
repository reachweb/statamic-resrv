<?php

namespace Reach\StatamicResrv\Jobs;

use Carbon\Carbon;
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
use Reach\StatamicResrv\Models\RatePrice;
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
                // CSV cells reach us unvalidated and upsert() bypasses Eloquent casts/mutators, so
                // coerce and validate before writing: a non-numeric, blank, or negative price/
                // availability would otherwise be written straight to the tables and silently
                // corrupt pricing/inventory. Skip + log the row, mirroring the rate_id guard below.
                // resrv_availabilities.available is an integer inventory count, so a fractional
                // value (e.g. 1.9 or -0.5) is invalid — reject it instead of letting the (int) cast
                // below silently truncate it toward zero (1.9 → 1, -0.5 → 0).
                if (! is_numeric($data['price'] ?? null) || (float) $data['price'] < 0
                    || ! is_numeric($data['available'] ?? null) || (float) $data['available'] < 0
                    || floor((float) $data['available']) !== (float) $data['available']) {
                    Log::warning("Data import: skipping row for entry {$id} — non-numeric, negative, or fractional price/availability.");

                    return;
                }

                // date_start/date_end come straight from the CSV header (DataImport::getDatesFromHeader)
                // and bypass the numeric coercion above. A blank range parses to "now" and writes an
                // unintended row, an unparseable value makes CarbonPeriod::create() throw and abort the
                // whole import, and a reversed range yields an empty period that writes nothing without
                // a trace. Validate parseability and order, then skip+log the row like the guard above.
                if (blank($data['date_start'] ?? null) || blank($data['date_end'] ?? null)) {
                    Log::warning("Data import: skipping row for entry {$id} — blank date range.");

                    return;
                }

                try {
                    $periodStart = Carbon::parse($data['date_start']);
                    $periodEnd = Carbon::parse($data['date_end']);
                } catch (\Throwable $e) {
                    Log::warning("Data import: skipping row for entry {$id} — unparseable date range.");

                    return;
                }

                if ($periodStart->gt($periodEnd)) {
                    Log::warning("Data import: skipping row for entry {$id} — reversed date range (start after end).");

                    return;
                }

                $price = (float) $data['price'];
                $available = (int) $data['available'];

                $period = CarbonPeriod::create($periodStart, $periodEnd);
                $rateId = $data['rate_id'] ?? null;
                $isSharedRate = false;
                $sharedIndependentRateId = null;

                if ($rateId) {
                    $originalRateId = (int) $rateId;
                    $rate = Rate::withoutGlobalScopes()->find($originalRateId);
                    $resolvedId = app(AvailabilityRepository::class)->resolveBaseRateId($originalRateId);
                    $isSharedRate = $resolvedId !== $originalRateId;
                    if ($rate?->hasIndependentSharedPricing()) {
                        $sharedIndependentRateId = $originalRateId;
                    }
                    $rateId = $resolvedId;
                }

                if (! $rateId) {
                    $baseRateCount = Rate::forEntry($id)
                        ->get(['id', 'base_rate_id', 'availability_type'])
                        ->map(fn ($r) => ($r->base_rate_id && $r->isShared()) ? $r->base_rate_id : $r->id)
                        ->unique()
                        ->count();

                    if ($baseRateCount > 1) {
                        Log::warning("Data import: skipping row for entry {$id} — rate_id required when multiple rates exist.");

                        return;
                    }

                    if ($defaultRateId === null) {
                        $defaultRateId = Rate::forEntry($id)->value('id');
                        if ($defaultRateId !== null) {
                            $defaultRateId = app(AvailabilityRepository::class)->resolveBaseRateId((int) $defaultRateId);
                        }
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

                if ($isSharedRate) {
                    $existingDates = Availability::where('statamic_id', $id)
                        ->where('rate_id', $rateId)
                        ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                        ->pluck('date')
                        ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : $d)
                        ->all();

                    $dataToAdd = [];
                    $priceOverrides = [];
                    foreach ($period as $day) {
                        $dateStr = $day->isoFormat('YYYY-MM-DD');
                        if (in_array($dateStr, $existingDates)) {
                            $dataToAdd[] = [
                                'statamic_id' => $id,
                                'date' => $dateStr,
                                'price' => $price,
                                'available' => $available,
                                'rate_id' => $rateId,
                            ];
                            if ($sharedIndependentRateId !== null) {
                                $priceOverrides[] = [
                                    'rate_id' => $sharedIndependentRateId,
                                    'statamic_id' => $id,
                                    'date' => $dateStr,
                                    'price' => $price,
                                ];
                            }
                        }
                    }

                    if (! empty($dataToAdd)) {
                        Availability::upsert($dataToAdd, ['statamic_id', 'date', 'rate_id'], ['available']);
                    }

                    if (! empty($priceOverrides)) {
                        RatePrice::upsert($priceOverrides, ['rate_id', 'statamic_id', 'date'], ['price']);
                    }
                } else {
                    $dataToAdd = [];
                    foreach ($period as $day) {
                        $dataToAdd[] = [
                            'statamic_id' => $id,
                            'date' => $day->isoFormat('YYYY-MM-DD'),
                            'price' => $price,
                            'available' => $available,
                            'rate_id' => $rateId,
                        ];
                    }

                    Availability::upsert($dataToAdd, ['statamic_id', 'date', 'rate_id'], ['price', 'available']);
                }
            });
        });

        Cache::forget('resrv-data-import');

        return true;
    }
}
