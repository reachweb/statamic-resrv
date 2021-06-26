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

    public function createEntries(Request $request)
    {
        $data = $request->validate([
            'entries' => 'required|array',
            'title' => 'required',
            'date_start' => 'nullable|date',
            'date_end' => 'nullable|date',
            'condition_type' => 'nullable',
            'condition_comparison' => 'nullable',
            'condition_value' => 'nullable',
            'amount_type' => 'required|string',
            'amount' => 'required|numeric',
        ]);

        $dynamicPricing = $this->dynamicPricing->create($data);
        $dynamicPricing->entries()->sync($data['entries']);

        return response()->json(['id' => $dynamicPricing['id']]);
    }
    
    public function createExtras(Request $request)
    {
        $data = $request->validate([
            'extras' => 'required|array',
            'title' => 'required',
            'date_start' => 'nullable|date',
            'date_end' => 'nullable|date',
            'condition_type' => 'nullable',
            'condition_comparison' => 'nullable',
            'condition_value' => 'nullable',
            'amount_type' => 'required|string',
            'amount' => 'required|numeric',
        ]);

        $dynamicPricing = $this->dynamicPricing->create($data);
        $dynamicPricing->extras()->sync($data['extras']);

        return response()->json(['id' => $dynamicPricing['id']]);
    }




}
