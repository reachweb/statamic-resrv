<?php

namespace Reach\StatamicResrv\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
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

        $defaultRecipients = Arr::pull($default, 'recipients', []);
        $globalRecipients = Arr::pull($global, 'recipients');
        $formRecipients = Arr::pull($form, 'recipients');

        $resolved = array_replace_recursive($default, $global, $form);
        $resolved['recipients'] = $this->normalizeRecipients($formRecipients ?? $globalRecipients ?? $defaultRecipients);

        return $resolved;
    }

    protected function globalEventConfig(string $eventKey): array
    {
        $nestedGlobal = config("resrv-config.reservation_emails.global.{$eventKey}");
        $flatGlobalRow = $this->flatGlobalEventRow($eventKey);
        if (is_array($nestedGlobal)) {
            if ($flatGlobalRow) {
                Log::warning('Both nested and flat global reservation email config are set for the same event. Nested config will be used.', [
                    'event' => $eventKey,
                ]);
            }

            return $this->normalizeEventConfig($nestedGlobal);
        }

        if (! $flatGlobalRow) {
            return [];
        }

        return $this->normalizeEventConfig($flatGlobalRow);
    }

    protected function formEventConfig(string $formHandle, string $eventKey): array
    {
        $nestedForms = config("resrv-config.reservation_emails.forms.{$formHandle}.{$eventKey}");
        $flatFormRow = $this->flatFormEventRow($formHandle, $eventKey);
        if (is_array($nestedForms)) {
            if ($flatFormRow) {
                Log::warning('Both nested and flat per-form reservation email config are set for the same event/form. Nested config will be used.', [
                    'event' => $eventKey,
                    'form' => $formHandle,
                ]);
            }

            return $this->normalizeEventConfig($nestedForms);
        }

        if (! $flatFormRow) {
            return [];
        }

        return $this->normalizeEventConfig($flatFormRow);
    }

    protected function flatGlobalEventRow(string $eventKey): ?array
    {
        $rows = config('resrv-config.reservation_emails_global', []);
        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ($row['event'] ?? null) !== $eventKey) {
                continue;
            }

            return $row;
        }

        return null;
    }

    protected function flatFormEventRow(string $formHandle, string $eventKey): ?array
    {
        $rows = config('resrv-config.reservation_emails_forms', []);
        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ($row['event'] ?? null) !== $eventKey) {
                continue;
            }

            if ($this->extractFormHandle(data_get($row, 'form')) !== $formHandle) {
                continue;
            }

            return $row;
        }

        return null;
    }

    protected function normalizeEventConfig(array $config): array
    {
        $fromAddress = data_get($config, 'from.address') ?? data_get($config, 'from_address');
        $fromName = data_get($config, 'from.name') ?? data_get($config, 'from_name');

        $normalized = [
            'enabled' => $this->toBool(data_get($config, 'enabled', true)),
            'from' => [
                'address' => is_string($fromAddress) && trim($fromAddress) !== '' ? trim($fromAddress) : null,
                'name' => is_string($fromName) && trim($fromName) !== '' ? trim($fromName) : null,
            ],
            'subject' => $this->nullableString(data_get($config, 'subject')),
            'markdown' => $this->nullableString(data_get($config, 'markdown')),
        ];

        $friendlyRecipients = $this->normalizeRecipientsFromFriendlyFields($config);
        if ($friendlyRecipients !== null) {
            $normalized['recipients'] = $friendlyRecipients;
        } elseif (Arr::has($config, 'recipients')) {
            $normalized['recipients'] = $this->normalizeRecipients(data_get($config, 'recipients', []));
        }

        return $normalized;
    }

    protected function normalizeRecipientsFromFriendlyFields(array $config): ?array
    {
        $hasSources = Arr::has($config, 'recipient_sources');
        $hasEmails = Arr::has($config, 'recipient_emails');

        if (! $hasSources && ! $hasEmails) {
            return null;
        }

        $rows = $this->normalizeRecipients(data_get($config, 'recipient_sources', []));
        $customEmails = $this->tokenizeCustomEmails(data_get($config, 'recipient_emails', ''));

        if (count($customEmails) > 0) {
            $rows[] = [
                'type' => 'custom',
                'emails' => $customEmails,
            ];
        }

        // Keep event defaults if the user did not configure anything in the friendly fields.
        if (count($rows) === 0) {
            return null;
        }

        return $rows;
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

        if (Arr::isAssoc($value)) {
            if (isset($value['type'])) {
                $row = $this->normalizeRecipientRow($value);

                return $row ? [$row] : [];
            }

            return collect($value)
                ->filter(fn ($enabled) => (bool) $enabled)
                ->keys()
                ->map(fn ($token) => $this->normalizeRecipientRow((string) $token))
                ->filter()
                ->values()
                ->all();
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

            $normalizedValue = strtolower($value);

            if (in_array($normalizedValue, ['customer', 'admins', 'affiliate'], true)) {
                return ['type' => $normalizedValue];
            }

            if (str_starts_with($normalizedValue, 'custom:')) {
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

        $type = strtolower(trim((string) ($row['type'] ?? '')));
        if ($type === '') {
            return null;
        }

        $normalized = ['type' => $type];

        if ($type === 'custom') {
            $normalized['emails'] = data_get($row, 'emails', '');
        }

        return $normalized;
    }

    protected function tokenizeCustomEmails(mixed $value): array
    {
        if (is_string($value)) {
            return collect(preg_split('/[,;\r\n|]+/', $value) ?: [])
                ->map(fn ($email) => trim($email))
                ->filter()
                ->values()
                ->all();
        }

        if (is_array($value)) {
            return collect($value)
                ->flatMap(fn ($item) => $this->tokenizeCustomEmails($item))
                ->values()
                ->all();
        }

        return [];
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
