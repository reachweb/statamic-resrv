<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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
    
    public function index()
    {
        $dynamic = $this->dynamicPricing->get();
        foreach ($dynamic as $pricing) {
            $pricing['entries'] = $pricing->entries;
            $pricing['extras'] = $pricing->extras;
        }
        return response()->json($dynamic);
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
            'amount_operation' => 'required|string',
            'amount_type' => 'required|string',
            'amount' => 'required|numeric',
        ]);

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
            'amount_operation' => 'required|string',
            'amount_type' => 'required|string',
            'amount' => 'required|numeric',
            'order' => 'required|integer',
        ]);

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

        $location = $this->dynamicPricing->findOrFail($data['id'])->changeOrder($data['order']);

        return response(200);
    }

    public function delete(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer'
        ]);
        $id = $data['id'];
        $dynamicPricing = $this->dynamicPricing->findOrFail($id);
        $dynamicPricing->entries()->detach();
        $dynamicPricing->extras()->detach();
        $this->dynamicPricing->destroy($id);
       
        return response(200);
    }

}
