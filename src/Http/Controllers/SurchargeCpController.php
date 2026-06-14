<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\Surcharge;

class SurchargeCpController extends Controller
{
    public function indexCp(): InertiaResponse
    {
        return Inertia::render('resrv::Surcharges/Index', [
            'surcharges' => $this->allSurcharges(),
            'options' => $this->optionsForPicker(),
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json($this->allSurcharges());
    }

    public function options(): JsonResponse
    {
        return response()->json($this->optionsForPicker());
    }

    protected function allSurcharges(): EloquentCollection
    {
        return Surcharge::orderBy('order')
            ->with(['firstOption:id,name', 'secondOption:id,name'])
            ->get();
    }

    protected function optionsForPicker(): EloquentCollection
    {
        return Option::where('published', true)
            ->orderBy('name')
            ->get(['id', 'name', 'collection']);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->withSlug($request->validate($this->validationRules()));

        $data['order'] = Surcharge::max('order') + 1;

        $surcharge = Surcharge::create($data);

        if ($request->inertia()) {
            return back();
        }

        return response()->json(['id' => $surcharge->id]);
    }

    public function update(Request $request, Surcharge $surcharge): JsonResponse|RedirectResponse
    {
        $data = $this->withSlug($request->validate($this->validationRules()));

        $surcharge->update($data);

        if ($request->inertia()) {
            return back();
        }

        return response()->json(['id' => $surcharge->id]);
    }

    public function destroy(Surcharge $surcharge): JsonResponse
    {
        $surcharge->delete();

        return response()->json(['message' => 'Surcharge deleted.']);
    }

    public function order(Request $request, Surcharge $surcharge): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'integer'],
        ]);

        $surcharge->changeOrder($data['order']);

        return response()->json(['message' => 'Order updated.']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withSlug(array $data): array
    {
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    protected function validationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'first_option_id' => ['required', 'integer', 'different:second_option_id', 'exists:resrv_options,id'],
            'second_option_id' => ['required', 'integer', 'exists:resrv_options,id'],
            'comparison' => ['required', Rule::in(['differs', 'matches'])],
            'price' => ['required', 'numeric', 'min:0'],
            'published' => ['boolean'],
        ];
    }
}
