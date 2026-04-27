<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\Customer;
use Reach\StatamicResrv\Models\Entry;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Facades\Entry as StatamicEntry;
use Statamic\Facades\Form as StatamicForm;

class ExportCpController extends Controller
{
    public const STATUSES = ['pending', 'confirmed', 'partner', 'cancelled', 'expired', 'refunded'];

    public const CUSTOMER_KEYS_CACHE_KEY = 'resrv.export.customer_data_keys';

    protected const STANDARD_CUSTOMER_KEYS = ['email', 'first_name', 'last_name', 'phone', 'address', 'city', 'postal_code', 'country'];

    protected const DEFAULT_FIELDS = ['reference', 'status', 'entry_title', 'entry_property', 'quantity', 'date_start', 'date_end', 'customer_email', 'total'];

    /** @var array<string, \Statamic\Contracts\Entries\Entry|null> */
    protected array $entryCache = [];

    protected ?array $cachedFieldDefinitions = null;

    public function indexCp()
    {
        return view('statamic-resrv::cp.export.index', [
            'fields' => $this->fieldMetadata(),
            'statuses' => self::STATUSES,
            'entries' => Entry::query()->orderBy('title')->get(['item_id', 'title'])->values(),
            'affiliates' => Affiliate::query()->orderBy('name')->get(['id', 'name', 'code'])->values(),
        ]);
    }

    public function count(Request $request)
    {
        $data = $this->validateForCount($request);

        return response()->json([
            'count' => $this->baseQuery($data)->count(),
        ]);
    }

    public function download(Request $request)
    {
        $data = $this->validateForDownload($request);
        $definitions = $this->fieldDefinitions();
        $fields = array_map(
            fn (string $key) => ['key' => $key] + $definitions[$key],
            $data['fields']
        );
        $filename = 'reservations-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($data, $fields) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_map(fn ($field) => $field['label'], $fields));

            $this->baseQuery($data)
                ->with(['customer', 'extras', 'options.values'])
                ->lazyById(500)
                ->each(function (Reservation $reservation) use ($handle, $fields) {
                    $row = [];
                    foreach ($fields as $field) {
                        $row[] = $this->sanitizeForCsv(($field['value'])($reservation));
                    }
                    fputcsv($handle, $row);
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    protected function validateForCount(Request $request): array
    {
        return $request->validate($this->baseRules());
    }

    protected function validateForDownload(Request $request): array
    {
        return $request->validate($this->baseRules() + [
            'fields' => 'required|array|min:1',
            'fields.*' => 'in:'.implode(',', array_keys($this->fieldDefinitions())),
        ]);
    }

    protected function baseRules(): array
    {
        return [
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
            'statuses' => 'sometimes|array',
            'statuses.*' => 'in:'.implode(',', self::STATUSES),
            'item_id' => 'sometimes|nullable|string',
            'affiliate_id' => 'sometimes|nullable|integer',
            'with_customer_data' => 'sometimes|boolean',
        ];
    }

    protected function baseQuery(array $data)
    {
        return Reservation::query()
            ->whereDate('date_start', '>=', $data['start'])
            ->whereDate('date_start', '<=', $data['end'])
            ->whereIn('status', $data['statuses'] ?? self::STATUSES)
            ->when(
                $data['item_id'] ?? null,
                fn ($query, $itemId) => $query->where('item_id', $itemId)
            )
            ->when(
                $data['affiliate_id'] ?? null,
                fn ($query, $affiliateId) => $query->whereHas(
                    'affiliate',
                    fn ($q) => $q->where('resrv_affiliates.id', $affiliateId)
                )
            )
            ->when(
                $data['with_customer_data'] ?? false,
                fn ($query) => $query->whereNotNull('customer_id')
            );
    }

    protected function fieldMetadata(): array
    {
        return collect($this->fieldDefinitions())
            ->map(fn (array $field, string $key) => [
                'key' => $key,
                'label' => $field['label'],
                'group' => $field['group'],
                'default' => in_array($key, self::DEFAULT_FIELDS, true),
            ])
            ->values()
            ->all();
    }

    protected function fieldDefinitions(): array
    {
        return $this->cachedFieldDefinitions ??= $this->buildFieldDefinitions();
    }

    protected function buildFieldDefinitions(): array
    {
        $base = [
            'reference' => [
                'label' => __('Reference'),
                'group' => __('Reservation'),
                'value' => fn (Reservation $r) => $r->reference,
            ],
            'status' => [
                'label' => __('Status'),
                'group' => __('Reservation'),
                'value' => fn (Reservation $r) => $r->status,
            ],
            'quantity' => [
                'label' => __('Quantity'),
                'group' => __('Reservation'),
                'value' => fn (Reservation $r) => $r->quantity,
            ],
            'date_start' => [
                'label' => __('Check-in'),
                'group' => __('Reservation'),
                'value' => fn (Reservation $r) => $r->date_start?->format('Y-m-d'),
            ],
            'date_end' => [
                'label' => __('Check-out'),
                'group' => __('Reservation'),
                'value' => fn (Reservation $r) => $r->date_end?->format('Y-m-d'),
            ],
            'created_at' => [
                'label' => __('Created at'),
                'group' => __('Reservation'),
                'value' => fn (Reservation $r) => $r->created_at?->format('Y-m-d H:i'),
            ],
            'payment_gateway' => [
                'label' => __('Payment gateway'),
                'group' => __('Reservation'),
                'value' => fn (Reservation $r) => $r->payment_gateway,
            ],
            'price' => [
                'label' => __('Price'),
                'group' => __('Pricing'),
                'value' => fn (Reservation $r) => $r->price?->format(),
            ],
            'payment' => [
                'label' => __('Payment'),
                'group' => __('Pricing'),
                'value' => fn (Reservation $r) => $r->payment?->format(),
            ],
            'payment_surcharge' => [
                'label' => __('Surcharge'),
                'group' => __('Pricing'),
                'value' => fn (Reservation $r) => $r->payment_surcharge?->format(),
            ],
            'total' => [
                'label' => __('Total'),
                'group' => __('Pricing'),
                'value' => fn (Reservation $r) => $r->total?->format(),
            ],
            'customer_email' => [
                'label' => __('Email'),
                'group' => __('Customer'),
                'value' => fn (Reservation $r) => $r->customer_data->get('email'),
            ],
            'customer_first_name' => [
                'label' => __('First name'),
                'group' => __('Customer'),
                'value' => fn (Reservation $r) => $r->customer_data->get('first_name'),
            ],
            'customer_last_name' => [
                'label' => __('Last name'),
                'group' => __('Customer'),
                'value' => fn (Reservation $r) => $r->customer_data->get('last_name'),
            ],
            'customer_phone' => [
                'label' => __('Phone'),
                'group' => __('Customer'),
                'value' => fn (Reservation $r) => $r->customer_data->get('phone'),
            ],
            'customer_address' => [
                'label' => __('Address'),
                'group' => __('Customer'),
                'value' => fn (Reservation $r) => $r->customer_data->get('address'),
            ],
            'customer_city' => [
                'label' => __('City'),
                'group' => __('Customer'),
                'value' => fn (Reservation $r) => $r->customer_data->get('city'),
            ],
            'customer_postal_code' => [
                'label' => __('Postal code'),
                'group' => __('Customer'),
                'value' => fn (Reservation $r) => $r->customer_data->get('postal_code'),
            ],
            'customer_country' => [
                'label' => __('Country'),
                'group' => __('Customer'),
                'value' => fn (Reservation $r) => $r->customer_data->get('country'),
            ],
            'entry_title' => [
                'label' => __('Item'),
                'group' => __('Entry'),
                'value' => fn (Reservation $r) => $this->resolveEntryField($r, 'title'),
            ],
            'entry_slug' => [
                'label' => __('Item slug'),
                'group' => __('Entry'),
                'value' => fn (Reservation $r) => $this->resolveEntryField($r, 'slug'),
            ],
            'entry_url' => [
                'label' => __('Item URL'),
                'group' => __('Entry'),
                'value' => fn (Reservation $r) => $this->resolveEntryField($r, 'url'),
            ],
            'entry_property' => [
                'label' => __('Property'),
                'group' => __('Entry'),
                'value' => fn (Reservation $r) => $this->resolvePropertyLabel($r),
            ],
            'entry_property_handle' => [
                'label' => __('Property handle'),
                'group' => __('Entry'),
                'value' => fn (Reservation $r) => $this->resolvePropertyHandle($r),
            ],
            'extras' => [
                'label' => __('Extras'),
                'group' => __('Add-ons'),
                'value' => fn (Reservation $r) => $r->extras
                    ->map(fn ($extra) => $extra->name.' × '.$extra->pivot->quantity)
                    ->implode(', '),
            ],
            'options' => [
                'label' => __('Options'),
                'group' => __('Add-ons'),
                'value' => fn (Reservation $r) => $r->options
                    ->map(function ($option) {
                        $value = $option->values->firstWhere('id', $option->pivot->value);

                        return $option->name.': '.($value?->name ?? '');
                    })
                    ->implode(', '),
            ],
        ];

        return $base + $this->dynamicCustomerFieldDefinitions($base);
    }

    /**
     * Build extra "customer_*" field definitions from keys actually present in
     * resrv_customers.data, using the configured checkout form(s) for nicer
     * labels when available.
     *
     * @param  array<string, array{label: string, group: string, value: callable}>  $base
     * @return array<string, array{label: string, group: string, value: callable}>
     */
    protected function dynamicCustomerFieldDefinitions(array $base): array
    {
        $labels = $this->checkoutFormLabels();
        $extras = [];

        foreach ($this->discoverCustomerDataKeys() as $key) {
            if (in_array($key, self::STANDARD_CUSTOMER_KEYS, true)) {
                continue;
            }

            $fieldKey = 'customer_'.$key;

            if (isset($base[$fieldKey])) {
                continue;
            }

            $extras[$fieldKey] = [
                'label' => $labels[$key] ?? Str::headline($key),
                'group' => __('Customer'),
                'value' => fn (Reservation $r) => $r->customer_data->get($key),
            ];
        }

        ksort($extras);

        return $extras;
    }

    /**
     * Scan the customers table for every top-level key that has actually been
     * stored in the `data` JSON column. Driver-agnostic — we let the
     * AsCollection cast parse each row and dedupe in PHP. Cached for a short
     * window to keep validation/page-load constant-time on large installs;
     * accept ~10 minutes of staleness for newly introduced form fields.
     *
     * @return array<int, string>
     */
    protected function discoverCustomerDataKeys(): array
    {
        return Cache::remember(self::CUSTOMER_KEYS_CACHE_KEY, now()->addMinutes(10), function () {
            $keys = [];

            Customer::query()
                ->select(['id', 'data'])
                ->whereNotNull('data')
                ->lazyById(500)
                ->each(function (Customer $customer) use (&$keys) {
                    if (! $customer->data) {
                        return;
                    }

                    foreach ($customer->data->keys() as $key) {
                        if (is_string($key) && $key !== '') {
                            $keys[$key] = true;
                        }
                    }
                });

            return array_keys($keys);
        });
    }

    /**
     * Build a map of `field handle => display label` by walking every
     * checkout form referenced in resrv-config (default + per-collection +
     * per-entry mappings). Used to give discovered customer fields nicer
     * labels than a humanized handle.
     *
     * @return array<string, string>
     */
    protected function checkoutFormLabels(): array
    {
        $handles = collect();

        $handles->push(
            config('resrv-config.checkout_forms.default')
            ?? config('resrv-config.checkout_forms_default')
            ?? config('resrv-config.form_name', 'checkout')
        );

        foreach ($this->configMappings('checkout_forms.entries', 'checkout_forms_entries') as $mapping) {
            $handles->push(data_get($mapping, 'form'));
        }

        foreach ($this->configMappings('checkout_forms.collections', 'checkout_forms_collections') as $mapping) {
            $handles->push(data_get($mapping, 'form'));
        }

        $labels = [];

        $handles
            ->map(fn ($value) => $this->normalizeFormHandle($value))
            ->filter()
            ->unique()
            ->each(function (string $handle) use (&$labels) {
                $form = StatamicForm::find($handle);
                if (! $form) {
                    return;
                }

                foreach ($form->fields() as $field) {
                    $display = $field->config()['display'] ?? null;
                    if (is_string($display) && $display !== '') {
                        $labels[$field->handle()] = $display;
                    }
                }
            });

        return $labels;
    }

    /**
     * @return array<int, mixed>
     */
    protected function configMappings(string $primary, string $legacy): array
    {
        $mappings = config('resrv-config.'.$primary);
        if (! is_array($mappings) || $mappings === []) {
            $mappings = config('resrv-config.'.$legacy, []);
        }

        if (is_array($mappings) && Arr::isAssoc($mappings)) {
            return collect($mappings)
                ->map(fn ($form) => ['form' => $form])
                ->values()
                ->all();
        }

        return is_array($mappings) ? $mappings : [];
    }

    protected function normalizeFormHandle(mixed $value): ?string
    {
        if (is_string($value)) {
            return trim($value) !== '' ? trim($value) : null;
        }

        if (is_array($value)) {
            $first = Arr::first($value);

            return is_string($first) && trim($first) !== '' ? trim($first) : null;
        }

        return null;
    }

    protected function sanitizeForCsv(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        // Inspect the first non-whitespace char: leading spaces/tabs/newlines
        // would otherwise let crafted input like "  =1+1" or "\t=1+1" slip
        // past the check while still being interpreted as a formula by some
        // spreadsheet apps. ltrim() strips space/tab/CR/LF, so the in_array
        // list only needs the formula-trigger characters themselves.
        $trimmed = ltrim($value);
        if ($trimmed === '') {
            return $value;
        }

        if (in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }

    protected function resolvePropertyLabel(Reservation $reservation): string
    {
        $itemId = $reservation->item_id;

        if (! array_key_exists($itemId, $this->entryCache)) {
            $this->entryCache[$itemId] = StatamicEntry::find($itemId);
        }

        // getPropertyAttributeLabel() reads $this->entry()->blueprint and
        // collection() — those don't exist on the array returned by
        // emptyEntry(), so a deleted entry would fatal the streamed CSV.
        // Fall back to the raw handle, matching how entry_title degrades.
        if (! $this->entryCache[$itemId]) {
            return $this->resolvePropertyHandle($reservation);
        }

        return (string) $reservation->getPropertyAttributeLabel();
    }

    protected function resolvePropertyHandle(Reservation $reservation): string
    {
        if ($reservation->type === 'parent') {
            return $reservation->childs()
                ->get()
                ->pluck('property')
                ->filter()
                ->unique()
                ->implode(',');
        }

        return (string) ($reservation->getAttributes()['property'] ?? '');
    }

    protected function resolveEntryField(Reservation $reservation, string $key): string
    {
        $itemId = $reservation->item_id;

        if (! array_key_exists($itemId, $this->entryCache)) {
            $this->entryCache[$itemId] = StatamicEntry::find($itemId);
        }

        $entry = $this->entryCache[$itemId];

        if (! $entry) {
            return $key === 'title' ? '## Entry deleted ##' : '';
        }

        return (string) match ($key) {
            'title' => $entry->get('title'),
            'slug' => $entry->slug(),
            'url' => $entry->url(),
        };
    }
}
