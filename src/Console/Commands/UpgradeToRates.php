<?php

namespace Reach\StatamicResrv\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Reach\StatamicResrv\Facades\AvailabilityField;
use Reach\StatamicResrv\Models\Rate;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Collection;

class UpgradeToRates extends Command
{
    use RunsInPlease;

    protected $signature = 'resrv:upgrade-to-rates {--dry-run : Show what would be changed without making any modifications}';

    protected $description = 'Upgrade from the property-based system to the rate-based system';

    protected bool $dryRun = false;

    protected int $updatedCount = 0;

    public function handle(): int
    {
        $this->dryRun = $this->option('dry-run');

        if ($this->dryRun) {
            $this->components->info('Running in dry-run mode. No changes will be made.');
            $this->newLine();
        }

        if (! $this->ratesTableReady()) {
            $this->components->error('The resrv_rates table does not exist or has no data. Please run migrations first.');

            return self::FAILURE;
        }

        $this->updateRateTitlesFromBlueprints();
        $this->detectCrossEntryConnections();

        $this->newLine();

        if ($this->dryRun) {
            $this->components->info("Dry run complete. {$this->updatedCount} rate title(s) would be updated.");
        } else {
            $this->components->info("Upgrade complete. {$this->updatedCount} rate title(s) updated.");
        }

        return self::SUCCESS;
    }

    protected function ratesTableReady(): bool
    {
        return Schema::hasTable('resrv_rates') && Rate::withoutGlobalScopes()->exists();
    }

    protected function updateRateTitlesFromBlueprints(): void
    {
        $this->components->task('Reading blueprint configurations', fn () => true);

        $collections = Collection::all()->filter(function ($collection) {
            return $collection->entryBlueprints()->some(function ($blueprint) {
                return AvailabilityField::blueprintHasAvailabilityField($blueprint);
            });
        });

        if ($collections->isEmpty()) {
            $this->components->warn('No collections with Resrv availability fields found.');

            return;
        }

        foreach ($collections as $collection) {
            $this->processCollection($collection);
        }
    }

    protected function processCollection($collection): void
    {
        foreach ($collection->entryBlueprints() as $blueprint) {
            if (! AvailabilityField::blueprintHasAvailabilityField($blueprint)) {
                continue;
            }

            $field = AvailabilityField::getField($blueprint);

            if (! $field) {
                continue;
            }

            $propertyLabels = $field->config()['advanced_availability'] ?? [];

            if (empty($propertyLabels)) {
                continue;
            }

            $this->components->twoColumnDetail(
                "Collection: <info>{$collection->title()}</info>",
                count($propertyLabels).' propert'.((count($propertyLabels) === 1) ? 'y' : 'ies').' found'
            );

            $entryIds = $collection->queryEntries()->get()->map(fn ($entry) => $entry->id());

            foreach ($propertyLabels as $slug => $label) {
                $rates = Rate::withoutGlobalScopes()
                    ->whereIn('statamic_id', $entryIds)
                    ->where('slug', $slug)
                    ->where('title', $slug)
                    ->get();

                if ($rates->isEmpty()) {
                    continue;
                }

                foreach ($rates as $rate) {
                    $this->updatedCount++;

                    if ($this->dryRun) {
                        $this->components->twoColumnDetail(
                            "  Would update rate <comment>{$slug}</comment>",
                            "<comment>{$slug}</comment> → <info>{$label}</info>"
                        );
                    } else {
                        $rate->update(['title' => $label]);
                        $this->components->twoColumnDetail(
                            "  Updated rate <comment>{$slug}</comment>",
                            "<info>{$label}</info>"
                        );
                    }
                }
            }
        }
    }

    protected function detectCrossEntryConnections(): void
    {
        $this->newLine();
        $this->components->task('Checking for cross-entry connected availabilities', fn () => true);

        $crossEntryTypes = ['same_slug', 'specific_slugs', 'entries'];
        $warnings = [];

        $collections = Collection::all()->filter(function ($collection) {
            return $collection->entryBlueprints()->some(function ($blueprint) {
                return AvailabilityField::blueprintHasAvailabilityField($blueprint);
            });
        });

        foreach ($collections as $collection) {
            foreach ($collection->entryBlueprints() as $blueprint) {
                if (! AvailabilityField::blueprintHasAvailabilityField($blueprint)) {
                    continue;
                }

                $field = AvailabilityField::getField($blueprint);

                if (! $field) {
                    continue;
                }

                $connectedConfig = $field->config()['connected_availabilities'] ?? [];

                if (empty($connectedConfig)) {
                    continue;
                }

                foreach ($connectedConfig as $rule) {
                    $type = $rule['connected_availability_type'] ?? null;

                    if (! in_array($type, $crossEntryTypes)) {
                        continue;
                    }

                    $warning = [
                        'collection' => $collection->title(),
                        'blueprint' => $blueprint->title(),
                        'type' => $type,
                    ];

                    if ($type === 'specific_slugs') {
                        $warning['slugs'] = $rule['slugs_to_sync'] ?? 'N/A';
                    }

                    if ($type === 'entries') {
                        $entryGroups = collect($rule['connected_entries'] ?? [])->map(function ($group) {
                            return implode(', ', $group['entries'] ?? []);
                        })->filter()->implode(' | ');
                        $warning['entries'] = $entryGroups ?: 'N/A';
                    }

                    $warnings[] = $warning;
                }
            }
        }

        if (empty($warnings)) {
            $this->components->info('No cross-entry connected availabilities detected.');

            return;
        }

        $this->newLine();
        $this->components->warn('Cross-entry connected availabilities detected!');
        $this->components->warn('The connected availability system has been replaced by the rate system.');
        $this->components->warn('Cross-entry connections (same_slug, specific_slugs, entries) are no longer supported.');
        $this->newLine();

        foreach ($warnings as $warning) {
            $this->components->twoColumnDetail(
                "<comment>{$warning['collection']}</comment> ({$warning['blueprint']})",
                "Type: <error>{$warning['type']}</error>"
            );

            if (isset($warning['slugs'])) {
                $this->components->twoColumnDetail('  Slugs', $warning['slugs']);
            }

            if (isset($warning['entries'])) {
                $this->components->twoColumnDetail('  Entry groups', $warning['entries']);
            }
        }

        $this->newLine();
        $this->line('  <info>Recommended workarounds:</info>');
        $this->line('  1. Manually manage availability across entries in the admin CP');
        $this->line('  2. Use a custom listener on ReservationCreated/ReservationCancelled events');
        $this->line('     to sync availability between entries');
        $this->line('  3. Consolidate related entries into a single entry with multiple rates');
        $this->newLine();
        $this->line('  The blueprint config is preserved for reference but no longer functional.');
    }
}
