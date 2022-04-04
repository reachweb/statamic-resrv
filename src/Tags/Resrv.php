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
}
