<?php

namespace Reach\StatamicResrv\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Reach\StatamicResrv\Exceptions\CheckoutFormNotFoundException;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;

class CheckoutFormResolver
{
    public function resolveForReservation(Reservation $reservation): FormContract
    {
        $entry = Entry::find($reservation->item_id);

        return $this->resolveFirstAvailable($this->candidateHandlesForEntry($entry));
    }

    public function resolveForEntryId(?string $entryId): FormContract
    {
        $entry = $entryId ? Entry::find($entryId) : null;

        return $this->resolveFirstAvailable($this->candidateHandlesForEntry($entry));
    }

    public function resolveHandleForReservation(Reservation $reservation): string
    {
        return (string) $this->resolveForReservation($reservation)->handle();
    }

    public function resolveHandleForEntry(?EntryContract $entry): string
    {
        return (string) $this->resolveFirstAvailable($this->candidateHandlesForEntry($entry))->handle();
    }

    protected function candidateHandlesForEntry(?EntryContract $entry): array
    {
        $candidates = [];

        $entryMapped = $this->entryMappedForm($entry);
        if ($entryMapped) {
            $candidates[] = $entryMapped;
        }

        // Backward compatibility with legacy entry-level override field.
        if ($entry && $entry->get('resrv_override_form')) {
            $candidates[] = $entry->get('resrv_override_form');
        }

        $collectionMapped = $this->collectionMappedForm($entry);
        if ($collectionMapped) {
            $candidates[] = $collectionMapped;
        }

        $candidates[] = $this->defaultFormHandle();

        return collect($candidates)
            ->map(fn ($candidate) => $this->extractFormHandle($candidate))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function resolveFirstAvailable(array $handles): FormContract
    {
        foreach ($handles as $handle) {
            $form = Form::find($handle);
            if ($form) {
                return $form;
            }

            Log::warning('Configured checkout form was not found. Trying next fallback.', [
                'checkout_form_handle' => $handle,
            ]);
        }

        $candidateList = implode(', ', $handles);

        throw new CheckoutFormNotFoundException("No valid checkout form was found. Tried: [{$candidateList}].");
    }

    protected function entryMappedForm(?EntryContract $entry): ?string
    {
        if (! $entry) {
            return null;
        }

        $entryId = (string) $entry->id();
        $mappings = $this->entryMappings();

        foreach ($mappings as $mapping) {
            $mappedEntryId = $this->extractEntryId(data_get($mapping, 'entry'));
            $formHandle = $this->extractFormHandle(data_get($mapping, 'form'));

            if (! $mappedEntryId || ! $formHandle) {
                continue;
            }

            if ($mappedEntryId === $entryId) {
                return $formHandle;
            }
        }

        return null;
    }

    protected function collectionMappedForm(?EntryContract $entry): ?string
    {
        if (! $entry || ! $entry->collection()) {
            return null;
        }

        $handle = $entry->collection()->handle();
        $mappings = $this->collectionMappings();

        foreach ($mappings as $mapping) {
            // `handle` is accepted as a legacy alias for collection key.
            $collectionHandle = $this->normalizeStringValue(data_get($mapping, 'collection'));
            $legacyHandle = $this->normalizeStringValue(data_get($mapping, 'handle'));

            if ($collectionHandle && $legacyHandle && $collectionHandle !== $legacyHandle) {
                Log::warning('Conflicting collection mapping keys detected in checkout form mapping. Skipping mapping row.', [
                    'collection' => $collectionHandle,
                    'handle' => $legacyHandle,
                ]);

                continue;
            }

            $mappedHandle = $collectionHandle ?: $legacyHandle;
            $formHandle = $this->extractFormHandle(data_get($mapping, 'form'));

            if (! $mappedHandle || ! $formHandle) {
                continue;
            }

            if ($mappedHandle === $handle) {
                return $formHandle;
            }
        }

        return null;
    }

    protected function entryMappings(): array
    {
        $mappings = config('resrv-config.checkout_forms.entries');
        if (! is_array($mappings) || $mappings === []) {
            $mappings = config('resrv-config.checkout_forms_entries', []);
        }

        if (is_array($mappings) && Arr::isAssoc($mappings)) {
            // Allow associative syntax: ["entry-id" => "form-handle"].
            return collect($mappings)
                ->map(fn ($form, $entry) => ['entry' => $entry, 'form' => $form])
                ->values()
                ->all();
        }

        return is_array($mappings) ? $mappings : [];
    }

    protected function collectionMappings(): array
    {
        $mappings = config('resrv-config.checkout_forms.collections');
        if (! is_array($mappings) || $mappings === []) {
            $mappings = config('resrv-config.checkout_forms_collections', []);
        }

        if (is_array($mappings) && Arr::isAssoc($mappings)) {
            // Allow associative syntax: ["collection-handle" => "form-handle"].
            return collect($mappings)
                ->map(fn ($form, $collection) => ['collection' => $collection, 'form' => $form])
                ->values()
                ->all();
        }

        return is_array($mappings) ? $mappings : [];
    }

    protected function defaultFormHandle(): string
    {
        $default = config('resrv-config.checkout_forms.default')
            ?? config('resrv-config.checkout_forms_default')
            ?? config('resrv-config.form_name', 'checkout');

        $handle = $this->extractFormHandle($default);

        if (! $handle) {
            Log::warning('Invalid checkout form default config. Falling back to "checkout".', [
                'resrv_config_value' => $default,
            ]);

            return 'checkout';
        }

        return $handle;
    }

    protected function extractEntryId(mixed $value): ?string
    {
        return $this->normalizeStringValue($value);
    }

    protected function extractFormHandle(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (is_string($value)) {
            return trim($value) !== '' ? trim($value) : null;
        }

        if (is_array($value)) {
            $first = Arr::first($value);

            if (is_string($first) && trim($first) !== '') {
                return trim($first);
            }
        }

        if ($value instanceof Collection) {
            return $this->extractFormHandle($value->all());
        }

        return null;
    }

    protected function normalizeStringValue(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }

        if (is_array($value)) {
            $first = Arr::first($value);

            return is_string($first) && trim($first) !== '' ? trim($first) : null;
        }

        return null;
    }
}
