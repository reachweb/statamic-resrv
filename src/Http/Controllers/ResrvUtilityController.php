<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Routing\Controller;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class ResrvUtilityController extends Controller
{
    public function entries()
    {
        $collections = $this->collectionsWithAvailabityField();
        $entries = Entry::query()
        ->whereIn('collection', $collections)
        ->where('site', Site::default())
        ->orderBy('title', 'asc')
        ->get(['id', 'title'])
        ->toAugmentedArray();

        return response()->json($entries);
    }

    public function refreshSession()
    {
        session()->flush();
    }

    protected function collectionsWithAvailabityField()
    {
        $collections = [];
        $allCollections = Collection::all();
        foreach ($allCollections as $collection) {
            foreach ($collection->entryBlueprints() as $blueprint) {
                foreach ($blueprint->fields()->all() as $field) {
                    if ($field->config()['type'] == 'resrv_availability') {
                        $collections[] = $collection->handle();
                    }
                }
            }
        }

        return $collections;
    }
}
