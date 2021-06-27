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

    public function create(Request $request)
    {
        $data = $request->validate([
            'entries' => 'required_without:extras|array',
            'extras' => 'required_without:entries|array',
            'title' => 'required',
            'date_start' => 'nullable|date',
            'date_end' => 'nullable|date',
            'date_include' => 'nullable',
            'condition_type' => 'nullable',
            'condition_comparison' => 'nullable',
            'condition_value' => 'nullable',
            'amount_operation' => 'required|string',
            'amount_type' => 'required|string',
            'amount' => 'required|numeric',
        ]);

        $order = $this->dynamicPricing->max('order') + 1;
        $data['order'] = $order;

        $dynamicPricing = $this->dynamicPricing->create($data);
        if (array_key_exists('entries', $data)) {
            $dynamicPricing->entries()->sync($data['entries']);
        } else {
            $dynamicPricing->extras()->sync($data['extras']);
        }        

        return response()->json(['id' => $dynamicPricing['id']]);
    }    
   

    public function update($id, Request $request)
    {
        $data = $request->validate([
            'entries' => 'required_without:extras|array',
            'extras' => 'required_without:entries|array',
            'title' => 'required',
            'date_start' => 'nullable|date',
            'date_end' => 'nullable|date',
            'date_include' => 'nullable',
            'condition_type' => 'nullable',
            'condition_comparison' => 'nullable',
            'condition_value' => 'nullable',
            'amount_operation' => 'required|string',
            'amount_type' => 'required|string',
            'amount' => 'required|numeric',
            'order' => 'required|integer',
        ]);

        $dynamicPricing = $this->dynamicPricing->findOrFail($id);
        $dynamicPricing->update($data);
        if (array_key_exists('entries', $data)) {
            $dynamicPricing->entries()->sync($data['entries']);
        } else {
            $dynamicPricing->extras()->sync($data['extras']);
        }

        return response()->json(['id' => $dynamicPricing['id']]);
    }

    public function delete($id)
    {
        $dynamicPricing = $this->dynamicPricing->findOrFail($id);
        $dynamicPricing->entries()->detach();
        $dynamicPricing->extras()->detach();
        $this->dynamicPricing->destroy($id);
       
        return response(200);
    }

}
