<?php

namespace Reach\StatamicResrv\Support;

use Reach\StatamicResrv\Models\Reservation;

class ReservationEmailRecipientResolver
{
    public function resolve(Reservation $reservation, array $recipientRows): array
    {
        $emails = collect($recipientRows)
            ->flatMap(fn ($row) => $this->emailsForRecipientRow($reservation, $row))
            ->map(fn ($email) => trim((string) $email))
            ->map(fn ($email) => strtolower($email))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values();

        return $emails->all();
    }

    protected function emailsForRecipientRow(Reservation $reservation, mixed $row): array
    {
        if (is_string($row)) {
            $row = ['type' => $row];
        }

        if (! is_array($row)) {
            return [];
        }

        $type = strtolower(trim((string) ($row['type'] ?? '')));

        return match ($type) {
            'customer' => $this->customerEmails($reservation),
            'admins' => $this->adminEmails(),
            'affiliate' => $this->affiliateEmails($reservation),
            'custom' => $this->customEmails(data_get($row, 'emails')),
            default => [],
        };
    }

    protected function customerEmails(Reservation $reservation): array
    {
        $email = $reservation->customer?->email;

        return $email ? [$email] : [];
    }

    protected function adminEmails(): array
    {
        return $this->parseEmailList(config('resrv-config.admin_email'));
    }

    protected function affiliateEmails(Reservation $reservation): array
    {
        if (! config('resrv-config.enable_affiliates')) {
            return [];
        }

        return $reservation->affiliate()
            ->get()
            ->filter(fn ($affiliate) => (bool) $affiliate->send_reservation_email)
            ->flatMap(fn ($affiliate) => $this->parseEmailList($affiliate->email))
            ->values()
            ->all();
    }

    protected function customEmails(mixed $emails): array
    {
        return $this->parseEmailList($emails);
    }

    protected function parseEmailList(mixed $value): array
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
                ->flatMap(fn ($item) => $this->parseEmailList($item))
                ->values()
                ->all();
        }

        return [];
    }
}
