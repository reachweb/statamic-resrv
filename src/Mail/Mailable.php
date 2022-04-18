<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Container\Container;
use Illuminate\Support\Arr;
use Illuminate\Mail\Mailable as LaravelMailable;
use Illuminate\Mail\Markdown;

class Mailable extends LaravelMailable
{
    /**
     * Override buildMarkdownView() to define new components path.
     *
     * @return array
     *
     * @throws \ReflectionException
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function buildMarkdownView()
    {
        /** @var Markdown $markdown */
        $markdown = Container::getInstance()->make(Markdown::class);

        // Use package resources path
        if (file_exists(resource_path().'/views/vendor/statamic-resrv/email/theme')) {
            $markdown->loadComponentsFrom([
                resource_path().'/views/vendor/statamic-resrv/email/theme',
            ]);
        } else {
            $markdown->loadComponentsFrom([
                __DIR__.'/../../resources/views/email/theme',
            ]);
        }

        $data = $this->buildViewData();

        return [
            'html' => $markdown->render($this->markdown, $data),
            'text' => $this->buildMarkdownText($markdown, $data),
        ];
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
