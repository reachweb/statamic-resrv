<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Mail\Mailable as LaravelMailable;
use Illuminate\Container\Container;
use Illuminate\Mail\Markdown;

class Mailable extends LaravelMailable
{
   /**
     * Override buildMarkdownView() to define new components path
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

        // use package resources path
        $markdown->loadComponentsFrom([
            __DIR__. '/../../resources/views/email/theme'
        ]);

        $data = $this->buildViewData();

        return [
            'html' => $markdown->render($this->markdown, $data),
            'text' => $this->buildMarkdownText($markdown, $data),
        ];
    }
}
