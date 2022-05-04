<?php

namespace Reach\StatamicResrv\Tags;

use Reach\StatamicResrv\Models\Location;
use Statamic\Tags\Tags;

class Resrv extends Tags
{
    public function locations()
    {
        return htmlspecialchars(Location::where('published', true)->get()->toJson(), ENT_QUOTES, 'UTF-8');
    }

    public function searchJson()
    {
        if (session()->missing('resrv_search')) {
            return json_encode([]);
        }

        return json_encode(session()->get('resrv_search'));
    }
}
