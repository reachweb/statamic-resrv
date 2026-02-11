<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
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
        $handles = Entry::query()
            ->select('collection')
            ->distinct()
            ->pluck('collection');

        $collections = $handles->map(fn (string $handle) => [
            'handle' => $handle,
            'title' => \Statamic\Facades\Collection::findByHandle($handle)?->title() ?? ucfirst($handle),
        ])->values();

        return response()->json($collections);
    }

    public function entries(string $collection): JsonResponse
    {
        $entries = Entry::where('collection', $collection)
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

        $rate = Rate::create($data);

        if (! $data['apply_to_all'] && ! empty($entries)) {
            $rate->entries()->sync($entries);
        }

        return response()->json(['id' => $rate->id]);
    }

    public function update(Request $request, Rate $rate): JsonResponse
    {
        $data = $request->validate($this->validationRules($request, $rate));

        $entries = Arr::pull($data, 'entries', []);

        $rate->update($data);

        if ($data['apply_to_all'] ?? $rate->apply_to_all) {
            $rate->entries()->detach();
        } else {
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

        if ($rate->reservations()->whereNotIn('status', ['completed', 'cancelled', 'refunded', 'expired'])->exists()) {
            return response()->json(
                ['message' => 'Cannot delete a rate that has active reservations.'],
                422
            );
        }

        $rate->forceDelete();

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
            'apply_to_all' => ['boolean'],
            'entries' => ['nullable', 'array'],
            'entries.*' => ['string'],
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
            'pricing_type' => ['required', Rule::in(['independent', 'relative'])],
            'base_rate_id' => [
                'nullable',
                'required_if:pricing_type,relative',
                'exists:resrv_rates,id',
                function ($attribute, $value, $fail) use ($collection) {
                    if ($value && Rate::where('id', $value)->where('collection', $collection)->doesntExist()) {
                        $fail('The base rate must belong to the same collection.');
                    }
                },
            ],
            'modifier_type' => ['nullable', 'required_if:pricing_type,relative', Rule::in(['percent', 'fixed'])],
            'modifier_operation' => ['nullable', 'required_if:pricing_type,relative', Rule::in(['increase', 'decrease'])],
            'modifier_amount' => ['nullable', 'required_if:pricing_type,relative', 'numeric', 'min:0'],
            'availability_type' => ['required', Rule::in(['independent', 'shared'])],
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
