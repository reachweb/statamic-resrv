<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Models\Option;
use Reach\StatamicResrv\Models\OptionValue;

class OptionCpController extends Controller
{
    protected $option;
    protected $value;

    public function __construct(Option $option, OptionValue $value)
    {
        $this->option = $option;
        $this->value = $value;
    }
    
    public function entryIndex($statamic_id)
    {
        $options = $this->option->entry($statamic_id)->with('values')->get();
        return response()->json($options);
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'name' => 'required',
            'slug' => 'required',
            'item_id' => 'required',
            'required' => 'required|boolean',
            'published' => 'required|boolean',
        ]);
        $order = $this->option->max('order') + 1;
        $data['order'] = $order;

        $option = $this->option->create($data);

        return response()->json(['id' => $option->id]);
    }
    
    public function createValue(Request $request, $id)
    {
        $option = $this->option->findOrFail($id);       

        $data = $request->validate([
            'name' => 'required',
            'price' => 'required|numeric',
            'price_type' => 'required',
            'published' => 'required|boolean',
        ]);

        $order = $this->value->where('option_id', $id)->max('order') + 1;
        $data['order'] = $order;
        $data['option_id'] = $option->id;

        $value = $this->value->create($data);

        return response()->json(['id' => $value->id]);
    }
    
    public function update(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
            'name' => 'required',
            'slug' => 'required',
            'item_id' => 'required',
            'order' => 'required|integer',
            'required' => 'required|boolean',
            'published' => 'required|boolean',
        ]);
        $option = $this->option->find($data['id'])->update($data);

        return response()->json(['id' => $data['id']]);
    }

    public function updateValue(Request $request, $id)
    {
        $option = $this->option->findOrFail($id);       

        $data = $request->validate([
            'id' => 'required|integer',
            'name' => 'required',
            'price' => 'required|numeric',
            'price_type' => 'required',
            'order' => 'required|integer',
            'published' => 'required|boolean',
        ]);

        $data['option_id'] = $option->id;

        $value = $this->value->find($data['id'])->update($data);

        return response()->json(['id' => $data['id']]);
    }

    public function delete(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer'
        ]);
        $option = $this->option->destroy($data['id']);

        $this->value->where('option_id', $data['id'])->delete();

        return response(200);
    }
    
    public function deleteValue(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer'
        ]);
        $value = $this->value->destroy($data['id']);

        return response(200);
    }

    public function order(Request $request)
    {
        $data = $request->validate([
            'id' => 'required',            
            'order' => 'required|integer',
        ]);

        $this->option->find($data['id'])->changeOrder($data['order']);

        return response(200);
    }
    
    public function orderValue(Request $request)
    {
        $data = $request->validate([
            'id' => 'required',            
            'order' => 'required|integer',
        ]);

        $this->value->find($data['id'])->changeOrder($data['order']);

        return response(200);
    }   

}
