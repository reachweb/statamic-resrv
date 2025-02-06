<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Models\Entry;
use Reach\StatamicResrv\Models\Extra;

class ExtraCpController extends Controller
{
    protected $extra;

    public function __construct(Extra $extra)
    {
        $this->extra = $extra;
    }

    public function indexCp()
    {
        return view('statamic-resrv::cp.extras.index');
    }

    public function index()
    {
        $extras = $this->extra->all();

        return response()->json($extras);
    }

    public function entryIndex($statamic_id)
    {
        $entry = Entry::whereItemId($statamic_id);
        $extras = $this->extra->with('entries')->get();

        $extras->each(function ($extra) use ($entry) {
            $extra->setAttribute('enabled', $extra->entries->contains($entry));
        });

        return response()->json($extras);
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'name' => 'required',
            'slug' => 'required',
            'category_id' => 'nullable|integer|exists:resrv_extra_categories,id',
            'description' => 'sometimes',
            'price' => 'required|numeric',
            'price_type' => 'required',
            'custom' => 'required_if:price_type,custom',
            'override_label' => 'string|nullable',
            'allow_multiple' => 'required|boolean',
            'maximum' => 'required_if:allow_multiple,true|integer|nullable',
            'published' => 'required|boolean',
        ]);
        $order = $this->extra->max('order') + 1;
        $data['order'] = $order;

        $extra = $this->extra->create($data);

        return response()->json(['id' => $extra->id]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
            'name' => 'required',
            'slug' => 'required',
            'category_id' => 'nullable|integer|exists:resrv_extra_categories,id',
            'description' => 'sometimes',
            'price' => 'required|numeric',
            'price_type' => 'required',
            'custom' => 'required_if:price_type,custom',
            'override_label' => 'string|nullable',
            'allow_multiple' => 'required|boolean',
            'maximum' => 'required_if:allow_multiple,true|integer|nullable',
            'order' => 'required|integer',
            'published' => 'required|boolean',
        ]);

        $extra = $this->extra->find($data['id'])->update($data);

        return response()->json(['id' => $data['id']]);
    }

    public function updateCategories(Request $request)
    {
        $data = $request->validate([
            '*.id' => 'required|integer',
            '*.category_id' => 'nullable|integer|exists:resrv_extra_categories,id',
            '*.order' => 'required|integer',
        ]);

        foreach ($data as $item) {
            $extra = $this->extra->find($item['id']);
            $extra->update([
                'category_id' => $item['category_id'],
                'order' => $item['order'],
            ]);
        }

        return response()->json(['success' => true]);
    }

    public function associate(Request $request, $statamic_id)
    {
        $data = $request->validate([
            'id' => 'required|integer',
        ]);

        $entry = Entry::whereItemId($statamic_id);
        $entry->extras()->attach($data['id']);

        return response(200);
    }

    public function disassociate(Request $request, $statamic_id)
    {
        $data = $request->validate([
            'id' => 'required|integer',
        ]);

        $entry = Entry::whereItemId($statamic_id);
        $entry->extras()->detach($data['id']);

        return response(200);
    }

    public function massAssociate(Request $request, $extra_id)
    {
        $data = $request->validate([
            'entries' => 'sometimes|array',
        ]);

        $extra = $this->extra->findOrFail($extra_id);
        $extra->entries()->sync($data['entries'] ?? []);

        return response(200);
    }

    public function conditions(Request $request, $extra_id)
    {
        $data = $request->validate([
            'conditions' => 'sometimes|array',
            'conditions.*' => 'array:operation,type,comparison,value,date_start,date_end,time_start,time_end',
            'conditions.*.operation' => 'required_with:conditions|string',
            'conditions.*.type' => 'required_with:conditions|string',
            'conditions.*.date_start' => 'required_if:conditions.*.type,reservation_dates',
            'conditions.*.date_end' => 'required_if:conditions.*.type,reservation_dates',
            'conditions.*.time_start' => 'required_if:conditions.*.type,pickup_time|required_if:conditions.*.type,dropoff_time',
            'conditions.*.time_end' => 'required_if:conditions.*.type,pickup_time|required_if:conditions.*.type,dropoff_time',
            'conditions.*.value' => 'required_if:conditions.*.type,extra_selected|required_if:conditions.*.type,reservation_duration',
            'conditions.*.comparison' => 'required_if:conditions.*.type,reservation_duration',
        ]);

        if ($data['conditions']) {
            $this->extra->find($extra_id)
                ->conditions()
                ->updateOrCreate(
                    ['extra_id' => $extra_id],
                    $data
                );
        } else {
            $this->extra->find($extra_id)
                ->conditions()
                ->delete();
        }

        return response(200);
    }

    public function move(Request $request, Extra $extra)
    {
        $data = $request->validate([
            'category_id' => 'nullable|integer|exists:resrv_extra_categories,id',
            'order' => 'required|integer',
        ]);

        $extra->category_id = $data['category_id'];
        $extra->save();

        $extra->changeOrder($data['order']);

        return response(200);
    }

    public function order(Request $request, Extra $extra)
    {
        $data = $request->validate([
            'order' => 'required|integer',
        ]);

        $extra->changeOrder($data['order']);

        return response(200);
    }

    public function delete(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
        ]);
        $extra = $this->extra->destroy($data['id']);

        DB::table('resrv_extra_conditions')
            ->where('extra_id', $data['id'])
            ->delete();

        DB::table('resrv_dynamic_pricing_assignments')
            ->where('dynamic_pricing_assignment_type', 'Reach\StatamicResrv\Models\Extra')
            ->where('dynamic_pricing_assignment_id', $data['id'])
            ->delete();

        return response(200);
    }

    public function entries(Extra $extra)
    {
        return response()->json($extra->entries);
    }
}
