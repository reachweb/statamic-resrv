<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Reach\StatamicResrv\Models\Rate;

class RateCpController extends Controller
{
    public function index(string $statamicId): JsonResponse
    {
        $rates = Rate::where('statamic_id', $statamicId)
            ->orderBy('order')
            ->get();

        return response()->json($rates);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->validationRules($request));

        $data['order'] = Rate::where('statamic_id', $data['statamic_id'])->max('order') + 1;

        $rate = Rate::create($data);

        return response()->json(['id' => $rate->id]);
    }

    public function update(Request $request, Rate $rate): JsonResponse
    {
        $data = $request->validate($this->validationRules($request, $rate));

        $rate->update($data);

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

        $rate->delete();

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
        $statamicId = $rate?->statamic_id ?? $request->input('statamic_id');
        $ignoreId = $rate?->id;

        return [
            'statamic_id' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('resrv_rates')->where(function ($query) use ($statamicId) {
                    return $query->where('statamic_id', $statamicId);
                })->ignore($ignoreId),
            ],
            'description' => ['nullable', 'string'],
            'pricing_type' => ['required', Rule::in(['independent', 'relative'])],
            'base_rate_id' => [
                'nullable',
                'required_if:pricing_type,relative',
                'exists:resrv_rates,id',
                function ($attribute, $value, $fail) use ($statamicId) {
                    if ($value && Rate::where('id', $value)->where('statamic_id', $statamicId)->doesntExist()) {
                        $fail('The base rate must belong to the same entry.');
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
            'min_stay' => ['nullable', 'integer', 'min:0'],
            'max_stay' => ['nullable', 'integer', 'min:0'],
            'refundable' => ['boolean'],
            'published' => ['boolean'],
        ];
    }
}
