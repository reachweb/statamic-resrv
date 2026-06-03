<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Models\Entry;

class UtilityCpController extends Controller
{
    public function entries()
    {
        // Only the columns the CP pickers consume — DynamicPricingPanel (item_id) and
        // ExtraMassAssignPanel (id), both labelled by title — so the mirror's bulky `options`
        // JSON and unused columns aren't dumped to the client.
        return response()->json(Entry::all(['id', 'item_id', 'title']));
    }
}
