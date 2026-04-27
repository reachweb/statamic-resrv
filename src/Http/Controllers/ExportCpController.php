<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Models\Affiliate;
use Reach\StatamicResrv\Models\Entry;
use Reach\StatamicResrv\Models\Reservation;
use Statamic\Facades\Entry as StatamicEntry;

class ExportCpController extends Controller
{
    public const STATUSES = ['pending', 'confirmed', 'partner', 'cancelled', 'expired', 'refunded'];

    protected const DEFAULT_FIELDS = ['reference', 'status', 'date_start', 'date_end', 'customer_email', 'total'];

    /** @var array<string, \Statamic\Contracts\Entries\Entry|null> */
    protected array $entryCache = [];

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
                ->lazy(500)
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
            ->orderBy('date_start');
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
        return [
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
    }

    protected function sanitizeForCsv(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)) {
            return "'".$value;
        }

        return $value;
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
