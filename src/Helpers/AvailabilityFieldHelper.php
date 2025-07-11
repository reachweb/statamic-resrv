<?php

namespace Reach\StatamicResrv\Helpers;

use Illuminate\Support\Facades\Cache;
use Reach\StatamicResrv\Exceptions\FieldNotFoundException;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

class AvailabilityFieldHelper
{
    private const CACHE_TTL = 3600; // 1 hour

    private const FIELD_TYPE = 'resrv_availability';

    /**
     * Get the availability field handle for a given blueprint
     */
    public function getHandle(Blueprint $blueprint): string
    {
        $cacheKey = "resrv_availability_field_handle_{$blueprint->namespace()}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($blueprint) {
            $field = $this->findAvailabilityField($blueprint);

            if (! $field) {
                throw new FieldNotFoundException(self::FIELD_TYPE, $blueprint->namespace());
            }

            return $field->handle();
        });
    }

    /**
     * Get the availability field for a given blueprint
     */
    public function getField(Blueprint $blueprint): ?Field
    {
        $cacheKey = "resrv_availability_field_{$blueprint->namespace()}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($blueprint) {
            return $this->findAvailabilityField($blueprint);
        });
    }

    /**
     * Check if a blueprint has an availability field
     */
    public function blueprintHasAvailabilityField(Blueprint $blueprint): bool
    {
        $cacheKey = "resrv_has_availability_field_{$blueprint->namespace()}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($blueprint) {
            return $this->findAvailabilityField($blueprint) !== null;
        });
    }

    /**
     * Clear cache for a specific blueprint
     */
    public function clearCacheForBlueprint(string $namespace): void
    {
        $keys = [
            "resrv_availability_field_handle_{$namespace}",
            "resrv_availability_field_{$namespace}",
            "resrv_has_availability_field_{$namespace}",
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Find the availability field in a blueprint by field type
     */
    private function findAvailabilityField(Blueprint $blueprint): ?Field
    {
        foreach ($blueprint->fields()->all() as $field) {
            if ($field->config()['type'] === self::FIELD_TYPE) {
                return $field;
            }
        }

        return null;
    }
}
