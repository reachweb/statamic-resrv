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
        $fixedPricing = $this->fixedPricing->entry($statamic_id)->get();
        return response()->json($fixedPricing);
    }

}
