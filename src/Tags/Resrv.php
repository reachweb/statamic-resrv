<?php

namespace Reach\StatamicResrv\Tags;

use Statamic\Tags\Tags;
use Statamic\Facades\Collection;
use Reach\StatamicResrv\Models\Location;

class Resrv extends Tags
{
    public function locations()
    {
        return htmlspecialchars(Location::where('published', true)->get()->toJson(), ENT_QUOTES, 'UTF-8');
    }

}
