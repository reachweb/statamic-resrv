<?php

namespace Reach\StatamicResrv\Console\Commands;

use Illuminate\Console\Command;
use Reach\StatamicResrv\Support\SettingsMigrator;
use Statamic\Console\RunsInPlease;

class MigrateSettings extends Command
{
    use RunsInPlease;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resrv:settings:migrate {--dry-run : Report what would change without saving}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed CP-managed Resrv settings from a published config/resrv-config.php file';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(SettingsMigrator $migrator)
    {
        $result = $migrator->migrateFromPublishedConfig($this->option('dry-run'));

        if ($result === null) {
            $this->info('No published config/resrv-config.php found — nothing to migrate.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing was saved.');
        }

        $result->report($this);

        if (! $result->hasChanges() && $result->conflicts === []) {
            $this->info('CP settings already up to date.');
        }

        return self::SUCCESS;
    }
}
