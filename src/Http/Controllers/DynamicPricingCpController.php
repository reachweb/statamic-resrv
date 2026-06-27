<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Reach\StatamicResrv\Models\Availability;
use Reach\StatamicResrv\Models\DynamicPricing;

class DynamicPricingCpController extends Controller
{
    protected $dynamicPricing;

    public function __construct(DynamicPricing $dynamicPricing)
    {
        $this->dynamicPricing = $dynamicPricing;
    }

    public function indexCp(Request $request): InertiaResponse
    {
        $filters = [
            'search' => $request->query('search', ''),
            'operation' => $request->query('operation', ''),
            'dates_active' => $request->query('dates_active', ''),
            'condition' => $request->query('condition', ''),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', config('statamic.cp.pagination_size', 25)),
        ];

        return Inertia::render('resrv::DynamicPricing/Index', [
            'timezone' => config('app.timezone', 'UTC'),
            'filters' => $filters,
            'pricings' => $this->paginatedPricings($request),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        // Backward-compatible flat-array response used by AffiliatesPanel.
        if ($request->has('coupons_only') && $request->coupons_only == 'true') {
            $dynamic = $this->dynamicPricing->query()
                ->whereNotNull('coupon')
                ->where('coupon', '!=', '')
                ->with('extras')
                ->get();

            $this->loadAssignedEntries($dynamic);

            return response()->json($dynamic);
        }

        return response()->json($this->paginatedPricings($request));
    }

    protected function paginatedPricings(Request $request): LengthAwarePaginator
    {
        $query = $this->dynamicPricing->query();

        if ($search = $request->input('search')) {
            $query->whereRaw('LOWER(title) LIKE ?', ['%'.mb_strtolower($search).'%']);
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
        $perPage = max(1, min($perPage, 100));

        $pricings = $query->with('extras')->paginate($perPage);

        $this->loadAssignedEntries($pricings->getCollection());

        return $pricings;
    }

    /**
     * Expose each rule's `entries` as the distinct assigned entry item_ids.
     *
     * The morphedByMany entries() relation resolves to one Availability row per date (a
     * flood with no item_id), and the getEntriesAttribute accessor would run one pivot
     * query per rule. Instead we read every assignment for the page in a single pivot
     * query and set it as the `entries` relation so serialization stays O(1) queries.
     */
    protected function loadAssignedEntries(EloquentCollection $pricings): void
    {
        if ($pricings->isEmpty()) {
            return;
        }

        $assignments = DB::table('resrv_dynamic_pricing_assignments')
            ->where('dynamic_pricing_assignment_type', Availability::class)
            ->whereIn('dynamic_pricing_id', $pricings->modelKeys())
            ->get(['dynamic_pricing_id', 'dynamic_pricing_assignment_id'])
            ->groupBy('dynamic_pricing_id');

        $pricings->each(fn (DynamicPricing $pricing) => $pricing->setRelation(
            'entries',
            $assignments->get($pricing->id, collect())->pluck('dynamic_pricing_assignment_id')->values()
        ));
    }

    public function create(Request $request): JsonResponse|RedirectResponse
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
            'published' => 'nullable|boolean',
        ] + $this->amountOperationRules($request));

        $order = $this->dynamicPricing->max('order') + 1;
        $data['order'] = $order;

        $dynamicPricing = $this->dynamicPricing->create($data);
        $dynamicPricing->entries()->sync($data['entries']);
        $dynamicPricing->extras()->sync($data['extras']);

        if ($request->inertia()) {
            return back();
        }

        return response()->json(['id' => $dynamicPricing['id']]);
    }

    public function update($id, Request $request): JsonResponse|RedirectResponse
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
            'published' => 'nullable|boolean',
        ] + $this->amountOperationRules($request));

        $dynamicPricing = $this->dynamicPricing->findOrFail($id);
        $dynamicPricing->update($data);
        $dynamicPricing->entries()->sync($data['entries']);
        $dynamicPricing->extras()->sync($data['extras']);

        if ($request->inertia()) {
            return back();
        }

        return response()->json(['id' => $dynamicPricing['id']]);
    }

    public function order(Request $request)
    {
        $data = $request->validate([
            'id' => 'required',
            'order' => 'required|integer',
        ]);

        $count = (int) $this->dynamicPricing->count();
        $order = max(1, min((int) $data['order'], max($count, 1)));

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
