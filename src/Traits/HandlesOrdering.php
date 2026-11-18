<?php

namespace Reach\StatamicResrv\Traits;

trait HandlesOrdering
{
    public function changeOrder($order)
    {
        $items = $this->orderingQuery()->orderBy('order')->get()->keyBy('id');

        // $order is the 1-based position in this list. Stored numbers can differ from positions
        // (gaps left by deletions, numbers once assigned across lists), so compare positions.
        if ($items->keys()->search($this->id) + 1 == $order) {
            return;
        }

        $movingItem = $items->pull($this->id);
        $count = ($order == 1 ? 2 : 1);

        foreach ($items as $item) {
            if ($count == $order) {
                $count++;
            }
            $item->order = $count;
            $item->saveOrFail();
            $count++;
        }
        $movingItem->order = $order;
        $movingItem->saveOrFail();
    }

    // The list the order is relative to. Override to scope it (e.g. to a parent).
    protected function orderingQuery()
    {
        return $this->newQuery();
    }
}
