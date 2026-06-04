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

    private const CACHE_PREFIXES = [
        'resrv_availability_field_handle',
        'resrv_availability_field',
        'resrv_has_availability_field',
    ];

    /**
     * Get the availability field handle for a given blueprint
     */
    public function getHandle(Blueprint $blueprint): string
    {
        return Cache::remember($this->cacheKey('resrv_availability_field_handle', $blueprint), self::CACHE_TTL, function () use ($blueprint) {
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
        return Cache::remember($this->cacheKey('resrv_availability_field', $blueprint), self::CACHE_TTL, function () use ($blueprint) {
            return $this->findAvailabilityField($blueprint);
        });
    }

    /**
     * Check if a blueprint has an availability field
     */
    public function blueprintHasAvailabilityField(Blueprint $blueprint): bool
    {
        return Cache::remember($this->cacheKey('resrv_has_availability_field', $blueprint), self::CACHE_TTL, function () use ($blueprint) {
            return $this->findAvailabilityField($blueprint) !== null;
        });
    }

    /**
     * Clear cache for a specific blueprint
     */
    public function clearCacheForBlueprint(Blueprint $blueprint): void
    {
        foreach (self::CACHE_PREFIXES as $prefix) {
            Cache::forget($this->cacheKey($prefix, $blueprint));
        }
    }

    private function cacheKey(string $prefix, Blueprint $blueprint): string
    {
        return "{$prefix}_{$blueprint->namespace()}_{$blueprint->handle()}";
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
