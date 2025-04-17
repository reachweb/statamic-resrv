<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Mail\Mailable as LaravelMailable;

class Mailable extends LaravelMailable
{
    protected function buildMarkdownView()
    {
        $this->markdownRenderer()->loadComponentsFrom(
            file_exists(resource_path().'/views/vendor/statamic-resrv/email/theme')
                ? [resource_path().'/views/vendor/statamic-resrv/email/theme']
                : [__DIR__.'/../../resources/views/email/theme']
        );

        return parent::buildMarkdownView();
    }
}
