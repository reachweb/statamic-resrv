<?php

namespace Reach\StatamicResrv\Support;

use Illuminate\Support\Collection;

class AvailabilitySortBuilder
{
    public const ALLOWED_FIELDS = ['price', 'discount'];

    public const ALLOWED_DIRECTIONS = ['asc', 'desc'];

    /**
     * Parse a `field:direction` string into a normalized directive.
     *
     * @return array{field: string, direction: string}|null
     */
    public static function parse(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $parts = array_pad(explode(':', trim($value), 2), 2, 'asc');
        $field = strtolower(trim($parts[0]));
        $direction = strtolower(trim($parts[1])) ?: 'asc';

        if (! in_array($field, self::ALLOWED_FIELDS, true)) {
            return null;
        }

        if (! in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            $direction = 'asc';
        }

        return ['field' => $field, 'direction' => $direction];
    }

    /**
     * Sort the entry IDs from a getAvailability() result by the requested field.
     *
     * @param  array  $availabilityResult  The full result from Availability::getAvailable()
     * @param  array{field: string, direction: string}  $directive
     * @return array<int, string|int> Ordered entry IDs (origin IDs)
     */
    public static function sort(array $availabilityResult, array $directive): array
    {
        $data = $availabilityResult['data'] ?? null;

        if (! $data instanceof Collection) {
            $data = collect($data ?? []);
        }

        $field = $directive['field'];
        $direction = $directive['direction'];

        $entries = $data->map(function ($items, $entryId) use ($field) {
            $cheapest = self::cheapestItem($items);

            return [
                'id' => (string) $entryId,
                'value' => self::valueFor($cheapest, $field),
            ];
        })->values()->all();

        $multiplier = $direction === 'desc' ? -1 : 1;

        usort($entries, function (array $a, array $b) use ($multiplier) {
            $valueDiff = $a['value'] <=> $b['value'];
            if ($valueDiff !== 0) {
                return $multiplier * $valueDiff;
            }

            return strnatcmp($a['id'], $b['id']);
        });

        return array_map(fn (array $entry) => $entry['id'], $entries);
    }

    /**
     * @param  Collection|array  $items
     */
    protected static function cheapestItem($items): array
    {
        if ($items instanceof Collection) {
            // Items per entry are already sorted price-asc by Availability::getAvailabilityCollection().
            $first = $items->first();

            return is_array($first) ? $first : ($first ? $first->all() : []);
        }

        if (is_array($items) && ! empty($items)) {
            $first = reset($items);

            return is_array($first) ? $first : (array) $first;
        }

        return [];
    }

    protected static function valueFor(array $item, string $field): float
    {
        if ($field === 'discount') {
            $price = self::numericPrice($item['price'] ?? null);
            $original = self::numericPrice($item['original_price'] ?? null);

            if ($original === null || $price === null) {
                return 0.0;
            }

            return max(0.0, $original - $price);
        }

        return self::numericPrice($item['price'] ?? 0) ?? 0.0;
    }

    protected static function numericPrice($value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        // Price values are formatted strings like "150.00" or possibly localized.
        // Strip non-numeric chars except dot/comma, then normalize to dot.
        $cleaned = preg_replace('/[^0-9.,\-]/', '', (string) $value);
        $cleaned = str_replace(',', '.', $cleaned);

        if ($cleaned === '' || ! is_numeric($cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }
}
