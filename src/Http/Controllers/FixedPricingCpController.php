<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Reach\StatamicResrv\Models\FixedPricing;

class FixedPricingCpController extends Controller
{
    protected $fixedPricing;

    public function __construct(FixedPricing $fixedPricing)
    {
        $this->fixedPricing = $fixedPricing;
    }
    
    public function index($statamic_id)
    {
        $fixedPricing = $this->fixedPricing->entry($statamic_id)->orderBy('days')->get();
        return response()->json($fixedPricing);
    }
    
    public function update(Request $request)
    {
        $data = $request->validate([
            'statamic_id' => 'required',
            'days' => 'required',
            'price' => 'required|numeric',            
        ]);

        $fixedPricing = $this->fixedPricing->updateOrCreate(
            ['statamic_id' => $data['statamic_id'], 'days' => $data['days']],
            ['price' => $data['price']]
        );

        return response()->json(['id' => $fixedPricing->id]);
    }

    public function delete(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer'
        ]);
        $fixedPricing = $this->fixedPricing->destroy($data['id']);

        return response(200);
    }

}
