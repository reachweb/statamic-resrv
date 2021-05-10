<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Models\Location;

class LocationCpController extends Controller
{
    protected $location;

    public function __construct(Location $location)
    {
        $this->location = $location;
    }

    public function indexCp()
    {
        return view('statamic-resrv::cp.locations.index');
    }
    
    public function index()
    {
        $locations = $this->location->all();
        return response()->json($locations);
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'name' => 'required',
            'slug' => 'required',
            'extra_charge' => 'nullable|numeric',
            'published' => 'required|boolean',
        ]);

        $order = $this->location->max('order') + 1;
        $data['order'] = $order;
        
        $location = $this->location->create($data);

        return response()->json(['id' => $location->id]);
    }
    
    public function update(Request $request)
    {
        $data = $request->validate([
            'id' => 'required',
            'name' => 'required',
            'slug' => 'required',
            'extra_charge' => 'nullable|numeric',
            'published' => 'required|boolean',
            'order' => 'required|integer',
        ]);

        $location = $this->location->find($data['id'])->update($data);

        return response()->json(['id' => $data['id']]);
    }
    
    public function order(Request $request)
    {
        $data = $request->validate([
            'id' => 'required',            
            'order' => 'required|integer',
        ]);

        $location = $this->location->find($data['id'])->changeOrder($data['order']);

        return response(200);
    }

    public function delete(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer'
        ]);
        $location = $this->location->destroy($data['id']);

        return response(200);
    }

}
