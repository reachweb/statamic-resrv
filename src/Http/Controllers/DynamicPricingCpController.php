<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Reach\StatamicResrv\Models\DynamicPricing;

class DynamicPricingCpController extends Controller
{
    protected $dynamicPricing;

    public function __construct(DynamicPricing $dynamicPricing)
    {
        $this->dynamicPricing = $dynamicPricing;
    }

    public function indexCp()
    {
        return view('statamic-resrv::cp.dynamicpricings.index');
    }

    public function index(Request $request)
    {
        $query = $this->dynamicPricing->query();

        // Backward-compatible flat-array response used by AffiliatesPanel.
        if ($request->has('coupons_only') && $request->coupons_only == 'true') {
            $query->whereNotNull('coupon')->where('coupon', '!=', '');
            $dynamic = $query->get();
            foreach ($dynamic as $pricing) {
                $pricing['entries'] = $pricing->entries;
                $pricing['extras'] = $pricing->extras;
            }

            return response()->json($dynamic);
        }

        if ($search = $request->input('search')) {
            $query->where('title', 'like', '%'.$search.'%');
        }

        if ($operation = $request->input('operation')) {
            if (in_array($operation, ['increase', 'decrease', 'minimum', 'maximum'], true)) {
                $query->where('amount_operation', $operation);
            }
        }

        if ($condition = $request->input('condition')) {
            if ($condition === 'none') {
                $query->whereNull('condition_type');
            } elseif (in_array($condition, ['reservation_duration', 'reservation_price', 'days_to_reservation'], true)) {
                $query->where('condition_type', $condition);
            }
        }

        if ($datesActive = $request->input('dates_active')) {
            $now = now();
            switch ($datesActive) {
                case 'active':
                    $query->where(function ($q) use ($now) {
                        $q->whereNull('date_start')->orWhere('date_start', '<=', $now);
                    })->where(function ($q) use ($now) {
                        $q->whereNull('date_end')->orWhere('date_end', '>=', $now);
                    })->where(function ($q) use ($now) {
                        $q->whereNull('expire_at')->orWhere('expire_at', '>=', $now);
                    });
                    break;
                case 'upcoming':
                    $query->where('date_start', '>', $now);
                    break;
                case 'expired':
                    $query->where(function ($q) use ($now) {
                        $q->where('date_end', '<', $now)
                          ->orWhere('expire_at', '<', $now);
                    });
                    break;
                case 'always':
                    $query->whereNull('date_start')
                          ->whereNull('date_end')
                          ->whereNull('expire_at');
                    break;
            }
        }

        $perPage = (int) ($request->input('per_page') ?? config('statamic.cp.pagination_size', 25));
        $paginator = $query->paginate($perPage);

        foreach ($paginator->items() as $pricing) {
            $pricing['entries'] = $pricing->entries;
            $pricing['extras'] = $pricing->extras;
        }

        return response()->json($paginator);
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'entries' => 'nullable|required_without:extras|array',
            'extras' => 'nullable|required_without:entries|array',
            'title' => 'required',
            'date_start' => 'nullable|date|required_with:date_include',
            'date_end' => 'nullable|date|required_with:date_include',
            'date_include' => 'nullable|required_with:date_start,date_end',
            'condition_type' => 'nullable|required_with:condition_comparison,condition_value',
            'condition_comparison' => 'nullable|required_with:condition_type,condition_value',
            'condition_value' => 'nullable|required_with:condition_comparison,condition_type',
            'amount' => 'required|numeric',
            'coupon' => 'nullable|regex:/^[\w*-]+$/',
            'expire_at' => 'nullable|date',
            'overrides_all' => 'nullable|boolean',
        ] + $this->amountOperationRules($request));

        $order = $this->dynamicPricing->max('order') + 1;
        $data['order'] = $order;

        $dynamicPricing = $this->dynamicPricing->create($data);
        $dynamicPricing->entries()->sync($data['entries']);
        $dynamicPricing->extras()->sync($data['extras']);

        return response()->json(['id' => $dynamicPricing['id']]);
    }

    public function update($id, Request $request)
    {
        $data = $request->validate([
            'entries' => 'nullable|required_without:extras|array',
            'extras' => 'nullable|required_without:entries|array',
            'title' => 'required',
            'date_start' => 'nullable|date|required_with:date_include',
            'date_end' => 'nullable|date|required_with:date_include',
            'date_include' => 'nullable|required_with:date_start,date_end',
            'condition_type' => 'nullable|required_with:condition_comparison,condition_value',
            'condition_comparison' => 'nullable|required_with:condition_type,condition_value',
            'condition_value' => 'nullable|required_with:condition_comparison,condition_type',
            'amount' => 'required|numeric',
            'order' => 'required|integer',
            'coupon' => 'nullable|regex:/^[\w*-]+$/',
            'expire_at' => 'nullable|date',
            'overrides_all' => 'nullable|boolean',
        ] + $this->amountOperationRules($request));

        $dynamicPricing = $this->dynamicPricing->findOrFail($id);
        $dynamicPricing->update($data);
        $dynamicPricing->entries()->sync($data['entries']);
        $dynamicPricing->extras()->sync($data['extras']);

        return response()->json(['id' => $dynamicPricing['id']]);
    }

    public function order(Request $request)
    {
        $data = $request->validate([
            'id' => 'required',
            'order' => 'required|integer',
        ]);

        $max = (int) $this->dynamicPricing->max('order');
        $order = max(1, min((int) $data['order'], $max > 0 ? $max : 1));

        $this->dynamicPricing->findOrFail($data['id'])->changeOrder($order);

        return response(200);
    }

    public function delete(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
        ]);
        $id = $data['id'];
        $dynamicPricing = $this->dynamicPricing->findOrFail($id);
        $dynamicPricing->entries()->detach();
        $dynamicPricing->extras()->detach();
        $this->dynamicPricing->destroy($id);

        return response(200);
    }

    private function amountOperationRules(Request $request): array
    {
        $allowedTypes = in_array($request->input('amount_operation'), ['minimum', 'maximum'])
            ? ['fixed']
            : ['percent', 'fixed'];

        return [
            'amount_operation' => 'required|in:increase,decrease,minimum,maximum',
            'amount_type' => ['required', Rule::in($allowedTypes)],
        ];
    }
}
