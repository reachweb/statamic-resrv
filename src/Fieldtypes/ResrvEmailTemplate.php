<?php

namespace Reach\StatamicResrv\Fieldtypes;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Fieldtypes\Select;

class ResrvEmailTemplate extends Select
{
    protected $component = 'select';

    protected $icon = 'select';

    protected $selectable = false;

    protected $selectableInForms = false;

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function getOptions(): array
    {
        return collect($this->templateDirectories())
            ->filter(fn (string $directory) => File::isDirectory($directory))
            ->flatMap(fn (string $directory) => File::glob($directory.'/*.blade.php'))
            ->map(fn (string $path) => Str::before(basename($path), '.blade.php'))
            ->filter(fn (string $name) => $name !== '' && ! str_contains($name, '.'))
            ->unique()
            ->sort()
            ->map(fn (string $name) => [
                'value' => 'statamic-resrv::email.reservations.'.$name,
                'label' => Str::ucfirst(str_replace('-', ' ', $name)),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function templateDirectories(): array
    {
        return [
            resource_path('views/vendor/statamic-resrv/email/reservations'),
            __DIR__.'/../../resources/views/email/reservations',
        ];
    }
}
