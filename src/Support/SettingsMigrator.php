<?php

namespace Reach\StatamicResrv\Support;

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

        $cpManaged = array_intersect_key($publishedConfig, $blueprintFields);

        $seeded = collect($cpManaged)
            ->reject(fn ($value, $key) => array_key_exists($key, $raw))
            ->reject(fn ($value) => $value === null)
            ->reject(fn ($value, $key) => $key === 'admin_email' && $value === false)
            ->reject(fn ($value, $key) => $key === 'logo' && $this->meansNoLogo($value))
            ->reject(fn ($value, $key) => array_key_exists($key, $defaults) && $value === $defaults[$key])
            ->all();

        $conflicts = collect($cpManaged)
            ->filter(fn ($value, $key) => array_key_exists($key, $raw) && $raw[$key] !== $value)
            ->map(fn ($value, $key) => ['file' => $value, 'cp' => $raw[$key]])
            ->all();

        $stale = collect($publishedConfig)
            ->keys()
            ->reject(fn ($key) => array_key_exists($key, $blueprintFields))
            ->reject(fn ($key) => in_array($key, self::DEVELOPER_KEYS, true))
            ->values()
            ->all();

        $result = new SettingsMigrationResult(
            seeded: $seeded,
            conflicts: $conflicts,
            deletable: array_keys($cpManaged),
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
}
