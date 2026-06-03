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

    public function __construct(protected string $cacheKey = 'resrv-data-import') {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $dataImport = Cache::get($this->cacheKey);

        if (! $dataImport) {
            Log::warning('Data import job fired but cache entry was missing.');

            return;
        }

        $dataImport->prepare()->each(function ($item, $id) {
            $defaultRateId = null;

            $item->each(function ($data) use ($id, &$defaultRateId) {
                // upsert() bypasses Eloquent casts, so validate before writing. Fractional
                // availability (e.g. 1.9) is rejected rather than silently truncated to 1.
                if (! is_numeric($data['price'] ?? null) || (float) $data['price'] < 0
                    || ! is_numeric($data['available'] ?? null) || (float) $data['available'] < 0
                    || floor((float) $data['available']) !== (float) $data['available']) {
                    Log::warning("Data import: skipping row for entry {$id} — non-numeric, negative, or fractional price/availability.");

                    return;
                }

                // Use parseImportDate() instead of Carbon::parse(): it enforces strict YYYY-MM-DD
                // and rejects overflow dates (2024-02-30) and relative strings ("next monday").
                $periodStart = $this->parseImportDate($data['date_start'] ?? null);
                $periodEnd = $this->parseImportDate($data['date_end'] ?? null);

                if (! $periodStart || ! $periodEnd) {
                    Log::warning("Data import: skipping row for entry {$id} — blank or invalid date range (expected YYYY-MM-DD).");

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

        Cache::forget($this->cacheKey);
    }

    /**
     * Clear the cache key and log the error so a failed import doesn't block re-uploading.
     */
    public function failed(\Throwable $exception): void
    {
        Cache::forget($this->cacheKey);

        Log::error('Data import job failed.', ['error' => $exception->getMessage()]);
    }

    /**
     * Parse a date string as strict YYYY-MM-DD, returning null for anything else.
     * The round-trip check rejects overflow dates Carbon would silently normalize.
     */
    private function parseImportDate($value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable $e) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
