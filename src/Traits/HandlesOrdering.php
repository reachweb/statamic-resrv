<?php

namespace Reach\StatamicResrv\Traits;

trait HandlesOrdering
{
    public function changeOrder($order)
    {
        if ($this->order == $order) {
            return;
        }

        $items = $this->orderBy('order')->get()->keyBy('id');
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

}