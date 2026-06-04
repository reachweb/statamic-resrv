<?php

namespace Reach\StatamicResrv\Support;

use Illuminate\Console\Command;
use Statamic\Console\NullConsole;

class SettingsMigrationResult
{
    /**
     * @param  array<string, mixed>  $seeded
     * @param  array<string, array{file: mixed, cp: mixed}>  $conflicts
     * @param  list<string>  $deletable
     * @param  list<string>  $stale
     * @param  list<string>  $normalized
     */
    public function __construct(
        public array $seeded = [],
        public array $conflicts = [],
        public array $deletable = [],
        public array $stale = [],
        public array $normalized = [],
    ) {}

    public function hasChanges(): bool
    {
        return $this->seeded !== [] || $this->normalized !== [];
    }

    public function report(Command|NullConsole $console): void
    {
        if ($this->seeded !== []) {
            $console->info('Seeded into CP settings:');
            foreach ($this->seeded as $key => $value) {
                $console->line("  - {$key}: ".$this->stringify($value));
            }
        }

        if ($this->normalized !== []) {
            $console->info('Removed legacy values from CP settings:');
            foreach ($this->normalized as $key) {
                $console->line("  - {$key}");
            }
        }

        if ($this->conflicts !== []) {
            $console->warn('Conflicts (the CP value stays active — review your published config):');
            foreach ($this->conflicts as $key => $values) {
                $console->line("  - {$key}: file=".$this->stringify($values['file']).', CP='.$this->stringify($values['cp']));
            }
        }

        if ($this->deletable !== []) {
            $console->info('Safe to delete from config/resrv-config.php (now CP-managed):');
            $console->line('  '.implode(', ', $this->deletable));
        }

        if ($this->stale !== []) {
            $console->info('Stale keys no longer used by Resrv (delete at your leisure):');
            $console->line('  '.implode(', ', $this->stale));
        }

        $console->info('Developer keys to keep in the file:');
        $console->line('  '.implode(', ', SettingsMigrator::DEVELOPER_KEYS));
    }

    protected function stringify(mixed $value): string
    {
        return is_scalar($value) || $value === null
            ? var_export($value, true)
            : json_encode($value);
    }
}
