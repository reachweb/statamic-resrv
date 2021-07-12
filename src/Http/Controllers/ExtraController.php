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
            'item_id' => 'required'
        ]);

        $extras = Extra::entry($data['item_id'])
            ->where('published', true)
            ->orderBy('order')
            ->get();

        foreach ($extras as $extra) {            
            $extra->calculated_price = Extra::find($extra->id)->priceForDays($data)->format();
        }
       
        return response()->json($extras->keyBy('slug')->toArray());

    }

}
