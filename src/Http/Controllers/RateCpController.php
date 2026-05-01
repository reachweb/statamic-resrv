<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Helpers\ResrvHelper;
use Reach\StatamicResrv\Models\Entry;
use Reach\StatamicResrv\Models\Rate;

class RateCpController extends Controller
{
    public function indexCp(): View
    {
        return view('statamic-resrv::cp.rates.index');
    }

    public function index(Request $request): JsonResponse
    {
        $query = Rate::query();

        if ($request->has('collection')) {
            $query->forCollection($request->input('collection'));
        }

        return response()->json($query->orderBy('order')->with('entries')->get());
    }

    public function collections(): JsonResponse
    {
        return response()->json(ResrvHelper::collectionsWithResrv()->values());
    }

    public function entries(string $collection): JsonResponse
    {
        $entries = DB::table('resrv_entries')
            ->where('collection', $collection)
            ->whereNull('deleted_at')
            ->select('item_id as id', 'title')
            ->get();

        return response()->json($entries);
    }

    public function forEntry(string $statamicId): JsonResponse
    {
        $rates = Rate::forEntry($statamicId)
            ->orderBy('order')
            ->get();

        return response()->json($rates);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->validationRules($request));

        $entries = Arr::pull($data, 'entries', []);

        $data['order'] = Rate::where('collection', $data['collection'])->max('order') + 1;

        Rate::renameTrashedSlugs($data['collection'], $data['slug']);

        $rate = Rate::create($data);

        if (! $data['apply_to_all'] && ! empty($entries)) {
            $rate->entries()->sync($entries);
        }

        Cache::forget('resrv_rates_exist');

        return response()->json(['id' => $rate->id]);
    }

    public function update(Request $request, Rate $rate): JsonResponse
    {
        $data = $request->validate($this->validationRules($request, $rate));

        $hasEntries = array_key_exists('entries', $data);
        $entries = Arr::pull($data, 'entries', []);

        if (isset($data['slug']) && $data['slug'] !== $rate->slug) {
            Rate::renameTrashedSlugs($rate->collection, $data['slug']);
        }

        $rate->update($data);

        if ($data['apply_to_all'] ?? $rate->apply_to_all) {
            $rate->entries()->detach();
        } elseif ($hasEntries) {
            $rate->entries()->sync($entries);
        }

        return response()->json(['id' => $rate->id]);
    }

    public function destroy(Rate $rate): JsonResponse
    {
        if ($rate->dependentRates()->exists()) {
            return response()->json(
                ['message' => 'Cannot delete a rate that is used as a base for other rates.'],
                422
            );
        }

        if ($rate->reservations()->whereNotIn('status', ReservationStatus::terminal())->exists()) {
            return response()->json(
                ['message' => 'Cannot delete a rate that has active reservations.'],
                422
            );
        }

        if ($rate->childReservations()->whereHas('parent', fn ($q) => $q->whereNotIn('status', ReservationStatus::terminal()))->exists()) {
            return response()->json(
                ['message' => 'Cannot delete a rate that has active reservations.'],
                422
            );
        }

        DB::transaction(function () use ($rate) {
            $rate->availabilities()->delete();
            $rate->fixedPricing()->delete();
            $rate->ratePrices()->delete();
            $rate->delete();
        });

        Cache::forget('resrv_rates_exist');

        return response()->json(['message' => 'Rate deleted.']);
    }

    public function order(Request $request): JsonResponse
    {
        $data = $request->validate([
            '*.id' => 'required|integer',
            '*.order' => 'required|integer',
        ]);

        foreach ($data as $item) {
            Rate::withoutGlobalScopes()->where('id', $item['id'])->update(['order' => $item['order']]);
        }

        return response()->json(['message' => 'Order updated.']);
    }

    /** @return array<string, mixed> */
    protected function validationRules(Request $request, ?Rate $rate = null): array
    {
        $collection = $rate?->collection ?? $request->input('collection');
        $ignoreId = $rate?->id;

        return [
            'collection' => ['required', 'string'],
            'apply_to_all' => ['required', 'boolean'],
            'entries' => ['nullable', 'array'],
            'entries.*' => [
                'string',
                function ($attribute, $value, $fail) use ($collection) {
                    if (! Entry::where('item_id', $value)->where('collection', $collection)->exists()) {
                        $fail('The selected entry does not belong to this collection.');
                    }
                },
            ],
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('resrv_rates')->withoutTrashed()->where(function ($query) use ($collection) {
                    return $query->where('collection', $collection);
                })->ignore($ignoreId),
            ],
            'description' => ['nullable', 'string'],
            'pricing_type' => [
                'required',
                Rule::in(['independent', 'relative']),
                function ($attribute, $value, $fail) use ($rate) {
                    if ($rate && $rate->pricing_type !== $value) {
                        $fail('Pricing type cannot be changed after the rate is created. Delete and recreate the rate instead.');
                    }
                },
            ],
            'base_rate_id' => [
                'nullable',
                Rule::requiredIf(fn () => $request->input('pricing_type') === 'relative' || $request->input('availability_type') === 'shared'),
                'exists:resrv_rates,id',
                Rule::notIn([$rate?->id]),
                function ($attribute, $value, $fail) use ($collection) {
                    if (! $value) {
                        return;
                    }
                    $baseRate = Rate::where('id', $value)->where('collection', $collection)->first();
                    if (! $baseRate) {
                        $fail('The base rate must belong to the same collection.');

                        return;
                    }
                    if ($baseRate->isShared()) {
                        $fail('A shared rate cannot be used as a base rate.');
                    }
                    if ($baseRate->isRelative()) {
                        $fail('A relative rate cannot be used as a base rate.');
                    }
                },
            ],
            'modifier_type' => ['nullable', 'required_if:pricing_type,relative', Rule::in(['percent', 'fixed'])],
            'modifier_operation' => ['nullable', 'required_if:pricing_type,relative', Rule::in(['increase', 'decrease'])],
            'modifier_amount' => [
                'nullable', 'required_if:pricing_type,relative', 'numeric', 'min:0',
                Rule::when(
                    fn () => $request->input('modifier_type') === 'percent' && $request->input('modifier_operation') === 'decrease',
                    ['max:100']
                ),
            ],
            'availability_type' => [
                'required',
                Rule::in(['independent', 'shared']),
                function ($attribute, $value, $fail) use ($rate) {
                    if ($rate && $rate->availability_type !== $value) {
                        $fail('Availability type cannot be changed after the rate is created. Delete and recreate the rate instead.');
                    }
                },
            ],
            'require_price_override' => ['nullable', 'boolean'],
            'max_available' => ['nullable', 'integer', 'min:1'],
            'date_start' => ['nullable', 'date'],
            'date_end' => ['nullable', 'date'],
            'min_days_before' => ['nullable', 'integer', 'min:0'],
            'max_days_before' => ['nullable', 'integer', 'min:0'],
            'min_stay' => ['nullable', 'integer', 'min:0'],
            'max_stay' => ['nullable', 'integer', 'min:0'],
            'refundable' => ['boolean'],
            'published' => ['boolean'],
        ];
    }
}
