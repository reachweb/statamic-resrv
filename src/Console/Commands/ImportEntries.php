<?php

namespace Reach\StatamicResrv\Console\Commands;

use Illuminate\Console\Command;
use Reach\StatamicResrv\Facades\AvailabilityField;
use Reach\StatamicResrv\Models\Entry as ResrvEntry;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Collection;

class ImportEntries extends Command
{
    use RunsInPlease;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resrv:import-entries';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import the entries to the database (for existing sites)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Collection::all()
            ->filter(function ($collection) {
                return $collection->entryBlueprints()->some(function ($blueprint) {
                    return AvailabilityField::blueprintHasAvailabilityField($blueprint);
                });
            })
            ->each(function ($collection) {
                $collection->queryEntries()->get()->each(function ($entry) {
                    app(ResrvEntry::class)->syncToDatabase($entry);
                });
            });

        $this->info('Resrv enabled entries imported to the database');
    }
}
