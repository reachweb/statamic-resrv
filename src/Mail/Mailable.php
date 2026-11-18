<?php

namespace Reach\StatamicResrv\Mail;

use Illuminate\Mail\Mailable as LaravelMailable;
use Illuminate\Support\HtmlString;
use Reach\StatamicResrv\Events\BuildingReservationEmail;
use Reach\StatamicResrv\Helpers\ResrvHelper;
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

    /**
     * The offline instructions setting as literal text, like the pay page. The <div> makes the
     * markdown renderer pass it through as one HTML block (no list/emphasis/link parsing); every
     * break is "<br>\n", so no blank line ends that block and the plain-text part keeps its lines.
     * The /u flag matters: without it \R matches the 0x85 byte inside a character such as Greek υ
     * (CF 85), and the split character makes the markdown renderer throw.
     */
    protected function offlineInstructions(): ?HtmlString
    {
        $instructions = ResrvHelper::offlineInstructions();

        if ($instructions === null) {
            return null;
        }

        return new HtmlString('<div>'.preg_replace('/\R/u', "<br>\n", e($instructions)).'</div>');
    }

    protected function buildMarkdownView()
    {
        $published = resource_path('views/vendor/statamic-resrv/email/theme');
        $default = __DIR__.'/../../resources/views/email/theme';

        // A published theme overrides individual mail components; the packaged
        // theme is always registered as a fallback so a partial or empty publish
        // never falls through to Laravel's logo-less default header.
        $this->markdownRenderer()->loadComponentsFrom(
            is_dir($published) ? [$published, $default] : [$default]
        );

        return parent::buildMarkdownView();
    }
}
