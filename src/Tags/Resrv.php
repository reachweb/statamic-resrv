<?php

namespace Reach\StatamicResrv\Tags;

use Statamic\Tags\Tags;
use Statamic\Facades\Collection;
use Reach\StatamicResrv\Models\Availability;

class Resrv extends Tags
{
    public function collection()
    {
        $collection = $this->params->get('from');
        $order = $this->params->get('order', 'order');
        $entries = $this->getEntries($collection, $order);
        $entries = $this->addAvailability($entries);
        return json_encode($entries->toAugmentedArray());
    }

    protected function getEntries($collection, $order)
    {
        return Collection::find($collection)
            ->queryEntries()
            ->where('published', true)
            ->orderBy($order, 'asc')
            ->get();
    }

    protected function addAvailability($entries)
    {
        $entries->each(function ($entry, $key) {
            $availability_data = Availability::entry($entry->id())->get();
            $data = $availability_data->sortBy('date')->keyBy('date')->toArray();
            $cheapest = $availability_data->sortBy('price')->firstWhere('available', '>', '0');
            $entry->set('availability', $data);
            $entry->set('cheapest', $cheapest->price);
        });
        return $entries;
    }

}