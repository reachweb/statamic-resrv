<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Mail\Mailable as LaravelMailable;
use Reach\StatamicResrv\Events\BuildingReservationEmail;
use Reach\StatamicResrv\Models\Reservation;

class Mailable extends LaravelMailable
{
    protected ?string $markdownTemplateOverride = null;

    protected function dispatchBuildingEvent(?Reservation $reservation = null): void
    {
        BuildingReservationEmail::dispatch($this, $reservation);
    }

    public function applyResrvEmailConfig(array $config): static
    {
        $fromAddress = data_get($config, 'from.address');
        $fromName = data_get($config, 'from.name') ?? config('app.name', '');

        if (is_string($fromAddress) && trim($fromAddress) !== '') {
            $this->from(trim($fromAddress), is_string($fromName) ? trim($fromName) : config('app.name', ''));
        }

        $subject = data_get($config, 'subject');
        if (is_string($subject) && trim($subject) !== '') {
            $this->subject(trim($subject));
        }

        $markdown = data_get($config, 'markdown');
        if (is_string($markdown) && trim($markdown) !== '') {
            $this->markdownTemplateOverride = trim($markdown);
        }

        return $this;
    }

    protected function markdownTemplate(string $default): string
    {
        return $this->markdownTemplateOverride ?: $default;
    }

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
