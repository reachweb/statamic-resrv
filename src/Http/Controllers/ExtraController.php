<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Models\Extra;

class ExtraController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'date_start' => 'required|date',
            'date_end' => 'required|date',
            'quantity' => 'sometimes|integer',
            'item_id' => 'required'
        ]);

        $extras = Extra::getPriceForDates($data);
       
        return response()->json($extras->keyBy('slug')->toArray());

    }

}
