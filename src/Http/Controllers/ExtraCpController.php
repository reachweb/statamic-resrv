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

    public function index()
    {
        $extras = $this->extra->all();
        return response()->json($extras);
    }
    
    public function entryIndex($statamic_id)
    {
        $extras = $this->extra->entry($statamic_id)->get();
        return response()->json($extras);
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'name' => 'required',
            'slug' => 'required',
            'price' => 'required|numeric',
            'price_type' => 'required',
            'published' => 'required|boolean',
        ]);
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
            'published' => 'required|boolean',
        ]);

        $extra = $this->extra->find($data['id'])->update($data);

        return response()->json(['id' => $data['id']]);
    }

    public function associate(Request $request, $statamic_id)
    {
        $data = $request->validate([
            'id' => 'required|integer'
        ]);
        DB::table('statamicentry_extra')
            ->insert(
                ['extra_id' => $data['id'], 'statamicentry_id' => $statamic_id],
            );
        return response(200);
    }

    public function disassociate(Request $request, $statamic_id)
    {
        $data = $request->validate([
            'id' => 'required|integer'
        ]);
        DB::table('statamicentry_extra')
            ->where('extra_id', $data['id'])
            ->where('statamicentry_id', $statamic_id)
            ->delete();
        return response(200);
    }
}
