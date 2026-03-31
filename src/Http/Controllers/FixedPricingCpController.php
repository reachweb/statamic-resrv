<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Reach\StatamicResrv\Models\FixedPricing;
use Reach\StatamicResrv\Models\Rate;

class FixedPricingCpController extends Controller
{
    public function __construct(protected FixedPricing $fixedPricing) {}

    public function index($statamic_id)
    {
        $fixedPricing = $this->fixedPricing
            ->entry($statamic_id)
            ->orderBy('days')
            ->get()
            ->unique(fn ($row) => $row->days.'|'.$row->price);

        return response()->json($fixedPricing->values());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'id' => 'sometimes|integer',
            'statamic_id' => 'required',
            'days' => 'required',
            'price' => 'required|numeric',
        ]);

        Cache::forget('fixed_pricing_table');

        if (isset($data['id'])) {
            return $this->updateExisting($data);
        }

        return $this->createForAllRates($data);
    }

    private function updateExisting(array $data): JsonResponse
    {
        $existing = $this->fixedPricing->find($data['id']);

        if (! $existing) {
            return response()->json(['id' => null]);
        }

        $oldPrice = $existing->getRawOriginal('price');

        // Update all rows that share the same identity (synced duplicates across rates)
        $this->fixedPricing
            ->where('statamic_id', $data['statamic_id'])
            ->where('days', $existing->days)
            ->where('price', $oldPrice)
            ->update(['days' => $data['days'], 'price' => $data['price']]);

        return response()->json(['id' => $existing->id]);
    }

    private function createForAllRates(array $data): JsonResponse
    {
        $rateIds = $this->rateIdsForEntry($data['statamic_id']);

        $lastId = null;
        foreach ($rateIds as $rateId) {
            $row = $this->fixedPricing->updateOrCreate(
                ['statamic_id' => $data['statamic_id'], 'days' => $data['days'], 'rate_id' => $rateId],
                ['price' => $data['price']]
            );
            $lastId = $row->id;
        }

        return response()->json(['id' => $lastId]);
    }

    public function delete(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
        ]);

        $row = $this->fixedPricing->find($data['id']);

        if ($row) {
            $this->fixedPricing
                ->where('statamic_id', $row->statamic_id)
                ->where('days', $row->days)
                ->where('price', $row->getRawOriginal('price'))
                ->delete();

            Cache::forget('fixed_pricing_table');
        }

        return response(200);
    }

    private function rateIdsForEntry(string $statamicId): array
    {
        $rates = Rate::forEntry($statamicId)->get(['id', 'pricing_type', 'base_rate_id']);

        $ids = $rates->where('pricing_type', '!=', 'relative')->pluck('id')->all();

        if (empty($ids)) {
            // All rates are relative — resolve their base rate IDs
            $baseIds = $rates->pluck('base_rate_id')->filter()->unique()->values()->all();

            if (! empty($baseIds)) {
                return $baseIds;
            }

            // No rates at all — create a default
            $rate = Rate::findOrCreateDefaultForEntry($statamicId);

            return $rate ? [$rate->id] : [];
        }

        return $ids;
    }
}
