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

    
    // public function index()
    // {
    //     $extras = $this->extra->all();
    //     return response()->json($extras);
    // }
    
    // public function entryIndex($statamic_id)
    // {
    //     $extras = $this->extra->entry($statamic_id)->get();
    //     return response()->json($extras);
    // }

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

        $dynamicPricing = $this->dynamicPricing->createEntries($data);

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

        $dynamicPricing = $this->dynamicPricing->createExtras($data);

        return response()->json(['id' => $dynamicPricing['id']]);
    }




}
