<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Models\Option;

class OptionController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'date_start' => 'required|date',
            'date_end' => 'required|date',
            'item_id' => 'required'
        ]);

        $options = Option::entry($data['item_id'])
            ->where('published', true)
            ->with('values')
            ->get();

        foreach ($options as $index => $option) {
            $options[$index] = Option::find($option->id)->valuesPriceForDates($data);            
        }
               
        return response()->json($options->keyBy('slug')->toArray());

    }

}
