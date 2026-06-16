<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Helpers\ResrvHelper;
use Reach\StatamicResrv\Models\Entry;
use Reach\StatamicResrv\Models\Rate;

class RateCpController extends Controller
{
    public function indexCp(Request $request): InertiaResponse
    {
        $collections = ResrvHelper::collectionsWithResrv()->values();
        $selectedCollection = $request->query('collection', $collections->first()['handle'] ?? null);

        return Inertia::render('resrv::Rates/Index', [
            'collections' => $collections,
            'selectedCollection' => $selectedCollection,
            'rates' => $this->ratesForCollection($selectedCollection),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $collection = $request->input('collection');

        return response()->json($this->ratesForCollection($collection));
    }

    protected function ratesForCollection(?string $collection): EloquentCollection
    {
        $query = Rate::query();

        if ($collection !== null) {
            $query->forCollection($collection);
        }

        return $query->orderBy('order')
            ->with(['entries', 'baseRate:id,title'])
            ->withCount('dependentRates')
            ->get();
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
            ->with('baseRate:id,title')
            ->orderBy('order')
            ->get();

        // A shared rate is read-only in the availability UI and points the admin at its base rate to
        // manage inventory. If that base rate isn't itself assigned to this entry it would be absent
        // from the selector, leaving no editable rate at all — so pull in any such base rates. Their
        // inventory is what the shared children share, and a base rate is never itself shared/relative
        // (enforced on save), so it is always directly editable.
        $presentIds = $rates->pluck('id')->map(fn ($id) => (string) $id)->all();

        $missingBaseRateIds = $rates
            ->filter(fn (Rate $rate) => $rate->isShared() && $rate->base_rate_id)
            ->pluck('base_rate_id')
            ->map(fn ($id) => (string) $id)
            ->reject(fn (string $id) => in_array($id, $presentIds, true))
            ->unique()
            ->values();

        if ($missingBaseRateIds->isNotEmpty()) {
            $baseRates = Rate::whereIn('id', $missingBaseRateIds->all())
                ->with('baseRate:id,title')
                ->get();

            $rates = $rates->concat($baseRates)->sortBy('order')->values();
        }

        return response()->json($rates);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->applyCancellationPolicy($request->validate($this->validationRules($request)));

        $entries = Arr::pull($data, 'entries', []);

        $data['order'] = Rate::where('collection', $data['collection'])
            ->where('base_rate_id', $data['base_rate_id'] ?? null)
            ->max('order') + 1;

        Rate::renameTrashedSlugs($data['collection'], $data['slug']);

        $rate = Rate::create($data);

        if (! $data['apply_to_all'] && ! empty($entries)) {
            $rate->entries()->sync($entries);
        }

        Cache::forget('resrv_rates_exist');

        if ($request->inertia()) {
            return back();
        }

        return response()->json(['id' => $rate->id]);
    }

    public function update(Request $request, Rate $rate): JsonResponse|RedirectResponse
    {
        $data = $this->applyCancellationPolicy($request->validate($this->validationRules($request, $rate)), $rate);

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

        if ($request->inertia()) {
            return back();
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

    public function order(Request $request, Rate $rate): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'integer'],
        ]);

        $rate->changeOrder($data['order']);

        return response()->json(['message' => 'Order updated.']);
    }

    /** @return array<string, mixed> */
    protected function validationRules(Request $request, ?Rate $rate = null): array
    {
        $collection = $rate?->collection ?? $request->input('collection');
        $ignoreId = $rate?->id;

        return [
            'collection' => $rate
                ? ['required', 'string', Rule::in([$rate->collection])]
                : ['required', 'string'],
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
            'cancellation_policy' => ['nullable', Rule::in(['free_cancellation', 'non_refundable'])],
            'free_cancellation_period' => ['nullable', 'integer', 'min:0', 'required_if:cancellation_policy,free_cancellation'],
            'published' => ['boolean'],
        ];
    }

    /**
     * Keep the legacy `refundable` flag in sync with the policy that is actually enforced,
     * and drop a stale period when the policy doesn't use one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applyCancellationPolicy(array $data, ?Rate $rate = null): array
    {
        if (! array_key_exists('cancellation_policy', $data)) {
            // Legacy API payloads may still only send the old boolean — honor the intent.
            // The `boolean` rule also lets 0/'0'/1/'1' through, so normalize before comparing.
            $refundable = array_key_exists('refundable', $data)
                ? filter_var($data['refundable'], FILTER_VALIDATE_BOOL)
                : null;

            if ($refundable === false) {
                $data['cancellation_policy'] = 'non_refundable';
                $data['free_cancellation_period'] = null;
            } elseif ($refundable === true && $rate?->cancellation_policy === 'non_refundable') {
                // Marking a non-refundable rate refundable again must clear the stored policy,
                // or the saved flag and the policy that is actually enforced contradict each
                // other. A stored free-cancellation policy is left alone — it is already
                // refundable and its period is deliberate configuration.
                $data['cancellation_policy'] = null;
                $data['free_cancellation_period'] = null;
            }

            return $data;
        }

        $data['refundable'] = $data['cancellation_policy'] !== 'non_refundable';

        if ($data['cancellation_policy'] !== 'free_cancellation') {
            $data['free_cancellation_period'] = null;
        }

        return $data;
    }
}
