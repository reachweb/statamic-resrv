<?php

namespace Reach\StatamicResrv\Traits;

use Illuminate\Support\Arr;

trait HandlesFormOptions
{
    protected function getOption($option, $offset = 0)
    {
        $form = $this->reservation->getFormOptions();
        if ($form->email() && Arr::exists($form->email()[$offset], $option)) {
            return $form->email()[$offset][$option];
        }

        return false;
    }
}
