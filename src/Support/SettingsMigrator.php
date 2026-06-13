<?php

namespace Reach\StatamicResrv\Support;

use Illuminate\Support\Arr;
use Statamic\Addons\Settings;
use Statamic\Facades\Addon;

class SettingsMigrator
{
    public const DEVELOPER_KEYS = [
        'payment_gateway',
        'payment_gateways',
        'stripe_secret_key',
        'stripe_publishable_key',
        'stripe_webhook_secret',
    ];

    /**
     * Nested config keys retired in favour of the flat CP fields. A published config
     * still defining them is expanded into the matching flat keys, seeded into the CP,
     * and then reported as safe to delete — the resolvers no longer read the nested
     * shape, so the Control Panel is the single source for these settings.
     */
    public const MIGRATABLE_NESTED_KEYS = [
        'checkout_forms',
        'reservation_emails',
    ];

    /**
     * Load the site's published Resrv config file, or null when absent/unusable.
     * Shared by the migration entry points and the provider's CP guardrail.
     *
     * @return array<string, mixed>|null
     */
    public static function publishedConfig(): ?array
    {
        if (! is_file($path = config_path('resrv-config.php'))) {
            return null;
        }

        $published = require $path;

        return is_array($published) ? $published : null;
    }

    /**
     * Run the migration against the site's published config file and the addon's
     * settings store. Returns null when there is no published config to migrate.
     */
    public function migrateFromPublishedConfig(bool $dryRun = false): ?SettingsMigrationResult
    {
        $published = static::publishedConfig();
        $addon = Addon::get('reachweb/statamic-resrv');

        if ($published === null || ! $addon || ! $addon->hasSettingsBlueprint()) {
            return null;
        }

        return $this->migrate(
            $published,
            $addon->settings(),
            SettingsBlueprint::fields($addon->settingsBlueprint()->contents()),
            $dryRun
        );
    }

    /**
     * Seed CP-managed values from a published config file into the addon settings
     * store. Existing CP values always win; values equal to the blueprint default
     * are skipped so future default changes keep flowing through.
     *
     * The legacy no-logo sentinel is normalized away first so it neither blocks
     * seeding a file logo URL nor surfaces as a conflict.
     *
     * @param  array<string, mixed>  $publishedConfig
     * @param  array<string, array<string, mixed>>  $blueprintFields  top-level fields keyed by handle (see SettingsBlueprint::fields())
     */
    public function migrate(
        array $publishedConfig,
        Settings $settings,
        array $blueprintFields,
        bool $dryRun = false
    ): SettingsMigrationResult {
        $raw = $settings->raw();
        $defaults = SettingsBlueprint::defaultsFromFields($blueprintFields);

        $normalized = [];

        if (array_key_exists('logo', $raw) && $this->meansNoLogo($raw['logo'])) {
            unset($raw['logo']);
            $normalized[] = 'logo';
        }

        $fileFlat = array_intersect_key($publishedConfig, $blueprintFields);
        $cpManaged = array_merge($fileFlat, $this->expandNestedKeys($publishedConfig));

        $seeded = collect($cpManaged)
            ->reject(fn ($value, $key) => array_key_exists($key, $raw))
            ->reject(fn ($value) => $value === null)
            ->reject(fn ($value, $key) => $key === 'admin_email' && $value === false)
            ->reject(fn ($value, $key) => $key === 'logo' && $this->meansNoLogo($value))
            ->reject(fn ($value, $key) => array_key_exists($key, $defaults) && $value === $defaults[$key])
            ->all();

        // Conflicts only surface for keys written verbatim in the file. Flat values
        // expanded from a retired nested key resolve as "CP wins" silently — the
        // published-config warning already tells the user to delete the nested key.
        $conflicts = collect($fileFlat)
            ->filter(fn ($value, $key) => array_key_exists($key, $raw) && $raw[$key] !== $value)
            ->map(fn ($value, $key) => ['file' => $value, 'cp' => $raw[$key]])
            ->all();

        $migratableNestedPresent = array_keys(
            array_intersect_key($publishedConfig, array_flip(self::MIGRATABLE_NESTED_KEYS))
        );

        $deletable = array_values(array_unique(array_merge(array_keys($fileFlat), $migratableNestedPresent)));

        $stale = collect($publishedConfig)
            ->keys()
            ->reject(fn ($key) => array_key_exists($key, $blueprintFields))
            ->reject(fn ($key) => in_array($key, self::DEVELOPER_KEYS, true))
            ->reject(fn ($key) => in_array($key, self::MIGRATABLE_NESTED_KEYS, true))
            ->values()
            ->all();

        $result = new SettingsMigrationResult(
            seeded: $seeded,
            conflicts: $conflicts,
            deletable: $deletable,
            stale: $stale,
            normalized: $normalized,
        );

        if (! $dryRun && $result->hasChanges()) {
            $settings->set(array_merge($seeded, $raw))->save();
        }

        return $result;
    }

    protected function meansNoLogo(mixed $value): bool
    {
        return $value === false || $value === null || $value === '' || $value === 'false';
    }

    /**
     * Expand the retired nested checkout_forms / reservation_emails arrays from a
     * published config into the flat CP keys the blueprint and resolvers use. Empty
     * results are pruned so the old empty stub migrates nothing (keeps re-runs idempotent).
     *
     * @param  array<string, mixed>  $publishedConfig
     * @return array<string, mixed>
     */
    protected function expandNestedKeys(array $publishedConfig): array
    {
        $flat = [];

        $checkout = $publishedConfig['checkout_forms'] ?? null;
        if (is_array($checkout)) {
            if (($default = $this->scalarHandle($checkout['default'] ?? null)) !== null) {
                $flat['checkout_forms_default'] = $default;
            }

            if (($collections = $this->mappingRows($checkout['collections'] ?? null, 'collection')) !== []) {
                $flat['checkout_forms_collections'] = $collections;
            }

            if (($entries = $this->mappingRows($checkout['entries'] ?? null, 'entry')) !== []) {
                $flat['checkout_forms_entries'] = $entries;
            }
        }

        $emails = $publishedConfig['reservation_emails'] ?? null;
        if (is_array($emails)) {
            if (($global = $this->globalEmailRows($emails['global'] ?? null)) !== []) {
                $flat['reservation_emails_global'] = $global;
            }

            if (($forms = $this->formEmailRows($emails['forms'] ?? null)) !== []) {
                $flat['reservation_emails_forms'] = $forms;
            }
        }

        return $flat;
    }

    /**
     * Normalize a nested checkout-form mapping (a list of rows or an associative
     * handle => form map) into the list-of-rows shape the CP grid stores. Rows
     * missing either side are dropped.
     *
     * @return list<array<string, string>>
     */
    protected function mappingRows(mixed $value, string $keyField): array
    {
        if (! is_array($value) || $value === []) {
            return [];
        }

        if (Arr::isAssoc($value)) {
            $value = collect($value)
                ->map(fn ($form, $key) => [$keyField => $key, 'form' => $form])
                ->values()
                ->all();
        }

        return collect($value)
            ->map(function ($row) use ($keyField) {
                if (! is_array($row)) {
                    return null;
                }

                // 'handle' is the legacy alias the resolver accepts for a collection key.
                $key = $this->scalarHandle(
                    $row[$keyField] ?? ($keyField === 'collection' ? ($row['handle'] ?? null) : null)
                );
                $form = $this->scalarHandle($row['form'] ?? null);

                if ($key === null || $form === null) {
                    return null;
                }

                return [$keyField => $key, 'form' => $form];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Lift the nested reservation_emails.global.<event> map into the flat grid's
     * list of rows, moving the event key into an 'event' field.
     *
     * @return list<array<string, mixed>>
     */
    protected function globalEmailRows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $eventKey => $config) {
            if (! is_array($config)) {
                continue;
            }

            $rows[] = array_merge(['event' => (string) $eventKey], $config);
        }

        return $rows;
    }

    /**
     * Lift the nested reservation_emails.forms.<form>.<event> map into the flat grid's
     * list of rows, moving both the form and event keys into row fields.
     *
     * @return list<array<string, mixed>>
     */
    protected function formEmailRows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $formHandle => $events) {
            if (! is_array($events)) {
                continue;
            }

            foreach ($events as $eventKey => $config) {
                if (! is_array($config)) {
                    continue;
                }

                $rows[] = array_merge(['form' => (string) $formHandle, 'event' => (string) $eventKey], $config);
            }
        }

        return $rows;
    }

    protected function scalarHandle(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? $value : null;
        }

        if (is_array($value)) {
            $first = Arr::first($value);

            return is_string($first) && trim($first) !== '' ? trim($first) : null;
        }

        return null;
    }
}
