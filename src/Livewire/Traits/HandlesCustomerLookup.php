<?php

namespace Reach\StatamicResrv\Livewire\Traits;

use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Reach\StatamicResrv\Models\Reservation;

/**
 * Customer deep-link resolution (?ref=&hash=, HMAC from Reservation::customerLookupHash())
 * shared by the status and pay-by-link pages. Two rate-limit buckets: a tight per-(IP, reference)
 * bucket caps guesses per reference; a looser IP-wide bucket stops bypassing it by varying the
 * reference. Each component keys its own buckets via lookupRateLimiterPrefix().
 */
trait HandlesCustomerLookup
{
    #[Locked]
    public ?int $reservationId = null;

    /**
     * A deep link failed to resolve; the blade shows a neutral notice that never reveals
     * the cause (preserves the rate-limit and enumeration posture).
     */
    public bool $linkFailed = false;

    /** The RateLimiter key namespace for this page's lookup buckets. */
    abstract protected function lookupRateLimiterPrefix(): string;

    /** The reservation statuses this page's lookups resolve for. */
    abstract protected function visibleStatuses(): array;

    /** No deep-link params: form pages fall through to the form; deep-link-only pages override to fail. */
    protected function handleMissingLookupParams(): void {}

    protected function loadReservationFromUri(): void
    {
        $reference = request()->query('ref');
        $hash = request()->query('hash');

        if (! is_string($reference) || $reference === '' || ! is_string($hash) || $hash === '') {
            $this->handleMissingLookupParams();

            return;
        }

        // Any failure surfaces one neutral notice (never the cause) and shares the page's
        // per-(IP, reference) budget so mount can't brute-force the hash.
        if (strlen($hash) !== 64 || $this->tooManyLookupAttempts($reference)) {
            $this->linkFailed = true;

            return;
        }

        $reservation = Reservation::findForCustomerLookup($reference, $hash, $this->visibleStatuses());

        if ($reservation === null) {
            $this->recordFailedLookup($reference);
            $this->linkFailed = true;

            return;
        }

        $this->reservationId = $reservation->id;
    }

    protected function tooManyLookupAttempts(string $reference): bool
    {
        return RateLimiter::tooManyAttempts($this->rateLimiterKey($reference), 10)
            || RateLimiter::tooManyAttempts($this->ipRateLimiterKey(), 30);
    }

    protected function recordFailedLookup(string $reference): void
    {
        RateLimiter::hit($this->rateLimiterKey($reference), 600);
        RateLimiter::hit($this->ipRateLimiterKey(), 600);
    }

    /** Reference is normalized to match the lookup path. */
    protected function rateLimiterKey(string $reference): string
    {
        return $this->lookupRateLimiterPrefix().':'.sha1((string) request()->ip().'|'.strtoupper(trim($reference)));
    }

    protected function ipRateLimiterKey(): string
    {
        return $this->lookupRateLimiterPrefix().'-ip:'.sha1((string) request()->ip());
    }

    #[Computed]
    public function reservation(): ?Reservation
    {
        if (! $this->reservationId) {
            return null;
        }

        // Option values are soft-deleted to preserve reservation history — a value removed
        // after booking must still render, so load them withTrashed() like optionsForEmail().
        return Reservation::query()
            ->with(['customer', 'rate', 'extras', 'options.values' => fn ($query) => $query->withTrashed(), 'childs.rate'])
            ->find($this->reservationId);
    }
}
