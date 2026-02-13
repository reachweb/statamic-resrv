<?php

namespace Reach\StatamicResrv\Support;

use Illuminate\Support\Arr;
use Reach\StatamicResrv\Enums\ReservationEmailEvent;
use Reach\StatamicResrv\Exceptions\CheckoutFormNotFoundException;
use Reach\StatamicResrv\Models\Reservation;

class ReservationEmailConfigResolver
{
    public function __construct(
        protected CheckoutFormResolver $checkoutFormResolver,
    ) {}

    public function resolveForEvent(Reservation $reservation, ReservationEmailEvent|string $event): array
    {
        $eventKey = $event instanceof ReservationEmailEvent ? $event->value : (string) $event;
        $default = $this->defaultForEvent($eventKey);

        $global = $this->globalEventConfig($eventKey);

        $formHandle = null;
        try {
            $formHandle = $this->checkoutFormResolver->resolveHandleForReservation($reservation);
        } catch (CheckoutFormNotFoundException) {
            $formHandle = null;
        }

        $form = $formHandle ? $this->formEventConfig($formHandle, $eventKey) : [];

        $resolved = array_replace_recursive($default, $global, $form);
        $resolved['recipients'] = $this->normalizeRecipients(data_get($resolved, 'recipients', []));

        return $resolved;
    }

    protected function globalEventConfig(string $eventKey): array
    {
        $nestedGlobal = config("resrv-config.reservation_emails.global.{$eventKey}");
        if (is_array($nestedGlobal)) {
            return $this->normalizeEventConfig($nestedGlobal);
        }

        $rows = config('resrv-config.reservation_emails_global', []);
        if (! is_array($rows)) {
            return [];
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ($row['event'] ?? null) !== $eventKey) {
                continue;
            }

            return $this->normalizeEventConfig($row);
        }

        return [];
    }

    protected function formEventConfig(string $formHandle, string $eventKey): array
    {
        $nestedForms = config("resrv-config.reservation_emails.forms.{$formHandle}.{$eventKey}");
        if (is_array($nestedForms)) {
            return $this->normalizeEventConfig($nestedForms);
        }

        $rows = config('resrv-config.reservation_emails_forms', []);
        if (! is_array($rows)) {
            return [];
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ($row['event'] ?? null) !== $eventKey) {
                continue;
            }

            if ($this->extractFormHandle(data_get($row, 'form')) !== $formHandle) {
                continue;
            }

            return $this->normalizeEventConfig($row);
        }

        return [];
    }

    protected function normalizeEventConfig(array $config): array
    {
        $fromAddress = data_get($config, 'from.address') ?? data_get($config, 'from_address');
        $fromName = data_get($config, 'from.name') ?? data_get($config, 'from_name');

        return [
            'enabled' => $this->toBool(data_get($config, 'enabled', true)),
            'from' => [
                'address' => is_string($fromAddress) && trim($fromAddress) !== '' ? trim($fromAddress) : null,
                'name' => is_string($fromName) && trim($fromName) !== '' ? trim($fromName) : null,
            ],
            'subject' => $this->nullableString(data_get($config, 'subject')),
            'markdown' => $this->nullableString(data_get($config, 'markdown')),
            'recipients' => $this->normalizeRecipients(data_get($config, 'recipients', [])),
        ];
    }

    protected function defaultForEvent(string $event): array
    {
        return match ($event) {
            ReservationEmailEvent::CustomerConfirmed->value => [
                'enabled' => true,
                'from' => ['address' => null, 'name' => null],
                'subject' => null,
                'markdown' => 'statamic-resrv::email.reservations.confirmed',
                'recipients' => [['type' => 'customer']],
            ],
            ReservationEmailEvent::AdminMade->value => [
                'enabled' => true,
                'from' => ['address' => null, 'name' => null],
                'subject' => null,
                'markdown' => 'statamic-resrv::email.reservations.made',
                'recipients' => [['type' => 'admins'], ['type' => 'affiliate']],
            ],
            ReservationEmailEvent::CustomerRefunded->value => [
                'enabled' => true,
                'from' => ['address' => null, 'name' => null],
                'subject' => null,
                'markdown' => 'statamic-resrv::email.reservations.refunded',
                'recipients' => [['type' => 'customer'], ['type' => 'admins']],
            ],
            ReservationEmailEvent::CustomerAbandoned->value => [
                'enabled' => true,
                'from' => ['address' => null, 'name' => null],
                'subject' => null,
                'markdown' => 'statamic-resrv::email.reservations.abandoned',
                'recipients' => [['type' => 'customer']],
            ],
            default => [
                'enabled' => true,
                'from' => ['address' => null, 'name' => null],
                'subject' => null,
                'markdown' => null,
                'recipients' => [],
            ],
        };
    }

    protected function normalizeRecipients(mixed $value): array
    {
        if (is_string($value)) {
            $tokens = collect(explode(',', $value))
                ->map(fn ($token) => trim($token))
                ->filter()
                ->values()
                ->all();

            return $this->recipientRowsFromTokens($tokens);
        }

        if (! is_array($value)) {
            return [];
        }

        // Allow short associative style, e.g. ['admins' => true].
        if (Arr::isAssoc($value) && isset($value['type'])) {
            return [$this->normalizeRecipientRow($value)];
        }

        return collect($value)
            ->map(function ($row) {
                if (is_string($row)) {
                    return $this->normalizeRecipientRow($row);
                }

                if (is_array($row)) {
                    return $this->normalizeRecipientRow($row);
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function recipientRowsFromTokens(array $tokens): array
    {
        return collect($tokens)
            ->map(fn ($token) => $this->normalizeRecipientRow($token))
            ->filter()
            ->values()
            ->all();
    }

    protected function normalizeRecipientRow(array|string $row): ?array
    {
        if (is_string($row)) {
            $value = trim($row);
            if ($value === '') {
                return null;
            }

            if (in_array($value, ['customer', 'admins', 'affiliate'], true)) {
                return ['type' => $value];
            }

            if (str_starts_with($value, 'custom:')) {
                return [
                    'type' => 'custom',
                    'emails' => trim(substr($value, 7)),
                ];
            }

            // Single literal email token.
            return [
                'type' => 'custom',
                'emails' => $value,
            ];
        }

        $type = trim((string) ($row['type'] ?? ''));
        if ($type === '') {
            return null;
        }

        $normalized = ['type' => $type];

        if ($type === 'custom') {
            $normalized['emails'] = data_get($row, 'emails', '');
        }

        return $normalized;
    }

    protected function extractFormHandle(mixed $value): ?string
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

    protected function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    protected function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
