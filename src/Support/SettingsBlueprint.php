<?php

namespace Reach\StatamicResrv\Support;

class SettingsBlueprint
{
    /**
     * Collect the top-level fields of a parsed settings blueprint, keyed by handle.
     * Intentionally shallow: grid sub-fields (field.fields.*) are never visited.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fields(array $blueprint): array
    {
        $fields = [];

        foreach ($blueprint['tabs'] ?? [] as $tab) {
            foreach ($tab['sections'] ?? [] as $section) {
                foreach ($section['fields'] ?? [] as $field) {
                    if (isset($field['handle'], $field['field']) && is_array($field['field'])) {
                        $fields[$field['handle']] = $field['field'];
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * Extract typed default values from a parsed settings blueprint. Only explicit
     * top-level `default` keys are collected — never Field::defaultValue() (fieldtype
     * fallbacks) or Settings::all() (Antlers stringifies every scalar).
     *
     * @return array<string, mixed>
     */
    public static function defaults(array $blueprint): array
    {
        return static::defaultsFromFields(static::fields($blueprint));
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields  output of fields()
     * @return array<string, mixed>
     */
    public static function defaultsFromFields(array $fields): array
    {
        $defaults = [];

        foreach ($fields as $handle => $field) {
            if (array_key_exists('default', $field)) {
                $defaults[$handle] = $field['default'];
            }
        }

        return $defaults;
    }
}
