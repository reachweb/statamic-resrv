<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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
        $extras = $this->extra->entry($statamic_id)->get();

        $extras->transform(function ($extra) {
            $extra->conditions = $this->extra->find($extra->id)->conditions()->get();

            return $extra;
        });

        return response()->json($extras);
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'name' => 'required',
            'slug' => 'required',
            'price' => 'required|numeric',
            'price_type' => 'required',
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
            'price' => 'required|numeric',
            'price_type' => 'required',
            'allow_multiple' => 'required|boolean',
            'maximum' => 'required_if:allow_multiple,true|integer|nullable',
            'order' => 'required|integer',
            'published' => 'required|boolean',
        ]);

        $extra = $this->extra->find($data['id'])->update($data);

        return response()->json(['id' => $data['id']]);
    }

    public function associate(Request $request, $statamic_id)
    {
        $data = $request->validate([
            'id' => 'required|integer',
        ]);
        DB::table('resrv_statamicentry_extra')
            ->insert(
                ['extra_id' => $data['id'], 'statamicentry_id' => $statamic_id],
            );

        return response(200);
    }

    public function disassociate(Request $request, $statamic_id)
    {
        $data = $request->validate([
            'id' => 'required|integer',
        ]);
        DB::table('resrv_statamicentry_extra')
            ->where('extra_id', $data['id'])
            ->where('statamicentry_id', $statamic_id)
            ->delete();

        return response(200);
    }

    public function conditions(Request $request, $extra_id)
    {
        $data = $request->validate([
            'conditions' => 'sometimes|array',
            'conditions.*' => 'array:operation,type,comparison,value,date_start,date_end,time_start,time_end',
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

    public function order(Request $request)
    {
        $data = $request->validate([
            'id' => 'required',
            'order' => 'required|integer',
        ]);

        $extra = $this->extra->find($data['id'])->changeOrder($data['order']);

        return response(200);
    }

    public function delete(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
        ]);
        $extra = $this->extra->destroy($data['id']);

        DB::table('resrv_statamicentry_extra')
            ->where('extra_id', $data['id'])
            ->delete();

        DB::table('resrv_extra_conditions')
            ->where('extra_id', $data['id'])
            ->delete();

        DB::table('resrv_dynamic_pricing_assignments')
            ->where('dynamic_pricing_assignment_type', 'Reach\StatamicResrv\Models\Extra')
            ->where('dynamic_pricing_assignment_id', $data['id'])
            ->delete();

        return response(200);
    }
}
