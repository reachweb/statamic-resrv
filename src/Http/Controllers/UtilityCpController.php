<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Models\Entry;

class UtilityCpController extends Controller
{
    public function entries()
    {
        return response()->json(Entry::all());
    }
}
