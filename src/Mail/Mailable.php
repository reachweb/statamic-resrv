<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Container\Container;
use Illuminate\Mail\Mailable as LaravelMailable;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Arr;

class Mailable extends LaravelMailable
{
    // Override buildMarkdownView() to define new components path.
    // TODO: Remove the legacy code when we drop support for Laravel 9.
    protected function buildMarkdownView()
    {
        // Check if the markdownRenderer() method exists (Laravel 10 and later)
        if (method_exists($this, 'markdownRenderer')) {
            $this->markdownRenderer()->loadComponentsFrom(
                file_exists(resource_path().'/views/vendor/statamic-resrv/email/theme')
                    ? [resource_path().'/views/vendor/statamic-resrv/email/theme']
                    : [__DIR__.'/../../resources/views/email/theme']
            );

            return parent::buildMarkdownView();
        }
        // If markdownRenderer() doesn't exist (Laravel versions before 10)
        else {
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
