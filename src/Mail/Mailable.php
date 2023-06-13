<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Mail\Mailable as LaravelMailable;
use Illuminate\Support\Arr;

class Mailable extends LaravelMailable
{
    // Override buildMarkdownView() to define new components path.
    protected function buildMarkdownView()
    {
        $this->markdownRenderer()->loadComponentsFrom(
            file_exists(resource_path().'/views/vendor/statamic-resrv/email/theme')
                ? [resource_path().'/views/vendor/statamic-resrv/email/theme']
                : [__DIR__.'/../../resources/views/email/theme']
        );

        return parent::buildMarkdownView();
    }

    protected function getOption($option, $offset = 0)
    {
        $form = $this->reservation->getFormOptions();
        if ($form->email() && Arr::exists($form->email()[$offset], $option)) {
            return $form->email()[$offset][$option];
        }

        return false;
    }
}
