<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Facades\Availability as AvailabilityRepository;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Http\Requests\AvailabilityCpRequest;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\ChildReservation;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\RatePrice;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Support\ActiveReservationsGuard;

class AvailabilityCpController extends Controller
{
    public function index(string $statamic_id, ?string $identifier = null): JsonResponse
    {
        $rate = $identifier
            ? Rate::withoutGlobalScopes()->find((int) $identifier)
            : null;

        $resolvedIdentifier = $rate && $rate->isShared() && $rate->base_rate_id
            ? (int) $rate->base_rate_id
            : ($rate?->id ?? Availability::where('statamic_id', $statamic_id)->value('rate_id'));

        $results = Availability::where('statamic_id', $statamic_id)
            ->when($resolvedIdentifier, function (Builder $query, int $rateId) {
                $query->where('rate_id', $rateId);
            })
            ->get(['statamic_id', 'date', 'price', 'available', 'pending'])
            ->sortBy('date')
            ->keyBy('date');

        if ($rate && $rate->isShared() && $rate->isRelative()) {
            $results = $results->map(function ($row) use ($rate) {
                $row->price = $rate->calculatePrice($row->price)->format();

                return $row;
            });
        }

        if ($rate && $rate->hasIndependentSharedPricing()) {
            $overrides = RatePrice::where('rate_id', $rate->id)
                ->where('statamic_id', $statamic_id)
                ->get()
                ->keyBy(fn ($row) => Carbon::parse($row->getRawOriginal('date'))->toDateString());

            $results = $results->map(function ($row) use ($overrides) {
                $key = Carbon::parse($row->date)->toDateString();
                if ($overrides->has($key)) {
                    $row->price = Price::create($overrides->get($key)->getRawOriginal('price'))->format();
                    $row->price_override = true;
                } else {
                    $row->price = $row->price->format();
                    $row->price_override = false;
                }

                return $row;
            });
        }

        return response()->json([
            'data' => $results,
            'max_available' => (int) ($results->max('available') ?? 0),
        ]);
    }

    public function update(AvailabilityCpRequest $request): JsonResponse
    {
        $data = $request->validated();

        // validated() omits nullable keys absent from the request; normalise so the unconditional
        // is_null() reads below (and in the validation rule) don't hit undefined-array-key warnings.
        $data['price'] = $data['price'] ?? null;
        $data['available'] = $data['available'] ?? null;

        $rateIds = $data['rate_ids'] ?? $this->defaultRateIds($data['statamic_id']);

        foreach ($rateIds as $rateId) {
            $this->updateAvailability($data, (int) $rateId);
        }

        return response()->json(['statamic_id' => $data['statamic_id']]);
    }

    public function delete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'statamic_id' => 'required',
            'date_start' => 'required|date',
            'date_end' => 'required|date',
            'rate_ids' => 'sometimes|array',
        ]);

        $requestedRateIds = ! empty($data['rate_ids'])
            ? array_map(fn ($id) => (int) $id, $data['rate_ids'])
            : $this->defaultRateIds($data['statamic_id']);

        $rateIds = array_unique(array_map(
            fn ($id) => AvailabilityRepository::resolveBaseRateId($id),
            $requestedRateIds
        ));

        if (ActiveReservationsGuard::hasActiveReservationsForRange(
            $data['statamic_id'], $data['date_start'], $data['date_end'], $rateIds
        )) {
            return response()->json([
                'message' => __('Cannot delete availability while reservations are pending for this date range.'),
            ], 422);
        }

        // Sweep shared+independent rate price overrides by base_rate_id: removing the base row
        // must also remove sibling overrides, or recreating the base later revives stale prices.
        $sharedIndependentRateIds = Rate::withoutGlobalScopes()
            ->where('availability_type', 'shared')
            ->where('pricing_type', 'independent')
            ->whereIn('base_rate_id', $rateIds)
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($data, $rateIds, $sharedIndependentRateIds) {
            Availability::where('date', '>=', $data['date_start'])
                ->where('date', '<=', $data['date_end'])
                ->where('statamic_id', $data['statamic_id'])
                ->whereIn('rate_id', $rateIds)
                ->delete();

            if (! empty($sharedIndependentRateIds)) {
                RatePrice::where('date', '>=', $data['date_start'])
                    ->where('date', '<=', $data['date_end'])
                    ->where('statamic_id', $data['statamic_id'])
                    ->whereIn('rate_id', $sharedIndependentRateIds)
                    ->delete();
            }
        });

        return response()->json(['statamic_id' => $data['statamic_id']]);
    }

    /**
     * Admin escape hatch: clear pending reservation IDs that should have been removed by the
     * regular increment/expire path but somehow stuck (queue worker died, transient DB error,
     * etc.). Default mode only clears IDs whose reservation is in a terminal status. Force mode
     * clears every ID and logs the active ones for audit.
     */
    public function clearStuckPending(Request $request): JsonResponse
    {
        $data = $request->validate([
            'statamic_id' => ['required', 'string'],
            'date' => ['required', 'date'],
            'rate_id' => ['required', 'integer'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $force = (bool) ($data['force'] ?? false);

        return DB::transaction(function () use ($data, $force) {
            $row = Availability::where([
                'statamic_id' => $data['statamic_id'],
                'rate_id' => $data['rate_id'],
            ])
                ->whereDate('date', $data['date'])
                ->lockForUpdate()
                ->firstOrFail();

            $pending = $row->pending ?? [];
            if (empty($pending)) {
                return response()->json(['cleared' => 0, 'still_active' => []]);
            }

            $terminal = ReservationStatus::terminal();

            // Keys are namespaced ('r'<id> / 'c'<id>); bare integers are legacy entries resolved against both tables.
            $parsed = collect($pending)->map(function ($entry) {
                if (is_string($entry) && preg_match('/^([rc])(\d+)$/', $entry, $matches)) {
                    return ['key' => $entry, 'type' => $matches[1] === 'c' ? 'child' : 'normal', 'id' => (int) $matches[2]];
                }

                return ['key' => $entry, 'type' => 'legacy', 'id' => (int) $entry];
            });

            $normalIds = $parsed->whereIn('type', ['normal', 'legacy'])->pluck('id')->unique();
            $childIds = $parsed->whereIn('type', ['child', 'legacy'])->pluck('id')->unique();

            $reservations = $normalIds->isEmpty()
                ? collect()
                : Reservation::whereIn('id', $normalIds->all())->get(['id', 'status', 'quantity'])->keyBy('id');

            $childReservations = $childIds->isEmpty()
                ? collect()
                : ChildReservation::whereIn('resrv_child_reservations.id', $childIds->all())
                    ->join('resrv_reservations', 'resrv_child_reservations.reservation_id', '=', 'resrv_reservations.id')
                    ->get([
                        'resrv_child_reservations.id',
                        'resrv_child_reservations.quantity',
                        'resrv_reservations.status as parent_status',
                    ])
                    ->keyBy('id');

            $terminalKeys = [];
            $activeIds = [];
            $quantityByKey = [];

            foreach ($parsed as $entry) {
                $status = null;
                $quantity = 0;

                if ($entry['type'] !== 'child' && $reservations->has($entry['id'])) {
                    $status = $reservations[$entry['id']]->status;
                    $quantity = (int) $reservations[$entry['id']]->quantity;
                } elseif ($entry['type'] !== 'normal' && $childReservations->has($entry['id'])) {
                    $status = $childReservations[$entry['id']]->parent_status;
                    $quantity = (int) $childReservations[$entry['id']]->quantity;
                }

                $quantityByKey[$entry['key']] = $quantity;

                // Null status means the holder is gone from both tables — treat as terminal with quantity 0.
                if ($status === null || in_array($status, $terminal, true)) {
                    $terminalKeys[] = $entry['key'];
                } else {
                    $activeIds[] = $entry['id'];
                }
            }

            $activeIds = array_values(array_unique($activeIds));
            $toClear = $force ? array_values($pending) : $terminalKeys;

            if (empty($toClear)) {
                return response()->json([
                    'cleared' => 0,
                    'still_active' => $activeIds,
                ]);
            }

            $restoredQuantity = 0;
            foreach ($toClear as $key) {
                $restoredQuantity += $quantityByKey[$key] ?? 0;
            }

            $row->update([
                'available' => $row->available + $restoredQuantity,
                'pending' => array_values(array_diff($pending, $toClear)),
            ]);

            if ($force && ! empty($activeIds)) {
                Log::warning('Resrv: admin force-cleared active holds from availability row', [
                    'availability_id' => $row->id,
                    'forced_ids' => $activeIds,
                ]);
            }

            return response()->json([
                'cleared' => count($toClear),
                'still_active' => $force ? [] : $activeIds,
            ]);
        });
    }

    private function updateAvailability(array $data, int $rateId): void
    {
        $rate = Rate::withoutGlobalScopes()->find($rateId);
        $resolvedRateId = AvailabilityRepository::resolveBaseRateId($rateId);

        $isSharedIndependent = $rate && $rate->hasIndependentSharedPricing();

        // The price is written to this rate's own availability row only for plain rates. A relative
        // rate derives its price from the base modifier on read (never stored); a shared rate either
        // mirrors the base price (shared+relative) or keeps per-date overrides in resrv_rate_prices
        // (shared+independent). A legacy entry with no Rate row behaves like a plain rate.
        $writesPriceColumn = ! $rate || (! $rate->isShared() && ! $rate->isRelative());

        // Shared+relative rates derive their price from the modifier — block direct price edits.
        $skipPrice = ($resolvedRateId !== $rateId) && ! $isSharedIndependent;

        if ($skipPrice && ! is_null($data['price']) && is_null($data['available'])) {
            abort(422, __('Price cannot be edited directly for shared rates. Edit the base rate instead.'));
        }

        // Only block on inventory changes — price-only edits don't disturb the available/pending invariant.
        if (! is_null($data['available']) && ActiveReservationsGuard::hasActiveReservationsForRange(
            $data['statamic_id'], $data['date_start'], $data['date_end'], [$resolvedRateId]
        )) {
            throw new HttpResponseException(response()->json([
                'message' => __('Cannot edit availability while reservations are pending for this date range.'),
            ], 422));
        }

        DB::transaction(function () use ($data, $rateId, $resolvedRateId, $isSharedIndependent, $writesPriceColumn) {
            $period = CarbonPeriod::create($data['date_start'], $data['date_end']);
            $onlyDays = $data['onlyDays'] ?? null;

            foreach ($period as $day) {
                if ($onlyDays && ! in_array($day->dayOfWeek, $onlyDays)) {
                    continue;
                }

                $date = $day->isoFormat('YYYY-MM-DD');

                if ($isSharedIndependent) {
                    // No base row = no inventory; a price override here would be orphaned. Skip silently.
                    $baseRowExists = Availability::where([
                        'statamic_id' => $data['statamic_id'],
                        'date' => $date,
                        'rate_id' => $resolvedRateId,
                    ])->exists();

                    if (! $baseRowExists) {
                        continue;
                    }

                    if (! is_null($data['price'])) {
                        RatePrice::updateOrCreate([
                            'rate_id' => $rateId,
                            'statamic_id' => $data['statamic_id'],
                            'date' => $date,
                        ], [
                            'price' => $data['price'],
                        ]);
                    }
                }

                $toUpdate = [];

                if (! is_null($data['price']) && $writesPriceColumn) {
                    $toUpdate['price'] = $data['price'];
                }

                if (! is_null($data['available'])) {
                    $toUpdate['available'] = $data['available'];
                }

                if (empty($toUpdate)) {
                    continue;
                }

                if ($isSharedIndependent) {
                    Availability::where([
                        'statamic_id' => $data['statamic_id'],
                        'date' => $date,
                        'rate_id' => $resolvedRateId,
                    ])->update($toUpdate);

                    continue;
                }

                Availability::updateOrCreate([
                    'statamic_id' => $data['statamic_id'],
                    'date' => $date,
                    'rate_id' => $resolvedRateId,
                ], $toUpdate);
            }
        });
    }

    private function defaultRateIds(string $statamicId): array
    {
        $rate = Rate::forEntry($statamicId)->first();
        if ($rate) {
            return [$rate->id];
        }

        $rate = Rate::findOrCreateDefaultForEntry($statamicId);

        return $rate ? [$rate->id] : [];
    }
}
