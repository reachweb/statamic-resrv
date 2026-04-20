<?php

namespace Reach\StatamicResrv\Http\Payment;

use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\Money\Price as PriceClass;

class PaymentGatewayManager
{
    protected array $gateways = [];

    protected ?string $defaultName = null;

    public function __construct()
    {
        $this->resolveFromConfig();
    }

    protected function resolveFromConfig(): void
    {
        $multipleGateways = config('resrv-config.payment_gateways');

        if (! empty($multipleGateways) && is_array($multipleGateways)) {
            foreach ($multipleGateways as $name => $config) {
                if (! isset($config['class']) || ! is_subclass_of($config['class'], PaymentInterface::class)) {
                    throw new \InvalidArgumentException("Payment gateway [{$name}] must have a 'class' that implements PaymentInterface.");
                }

                if (isset($config['amount_limits']) && ! is_array($config['amount_limits'])) {
                    throw new \InvalidArgumentException(
                        "Payment gateway [{$name}] has invalid amount_limits: must be an array with 'min' and/or 'max' keys, got ".gettype($config['amount_limits']).'.'
                    );
                }

                $this->validateAmountLimits($name, $config['amount_limits'] ?? null);

                $this->gateways[$name] = [
                    'class' => $config['class'],
                    'label' => $config['label'] ?? null,
                    'surcharge' => $config['surcharge'] ?? null,
                    'amount_limits' => $config['amount_limits'] ?? null,
                    'instance' => null,
                ];
            }
            $this->defaultName = array_key_first($this->gateways);

            return;
        }

        // Fallback to single gateway config — resolve eagerly since there's only one
        $instance = app(PaymentInterface::class);
        $name = $instance->name();
        $this->gateways[$name] = [
            'class' => get_class($instance),
            'label' => $instance->label(),
            'surcharge' => null,
            'amount_limits' => null,
            'instance' => $instance,
        ];
        $this->defaultName = $name;
    }

    protected function validateAmountLimits(string $gateway, ?array $limits): void
    {
        if (! $limits) {
            return;
        }

        foreach (['min', 'max'] as $key) {
            if (! isset($limits[$key])) {
                continue;
            }

            // is_numeric() accepts formats BCMath rejects at runtime (scientific notation,
            // leading whitespace), so probe Price::create() — the actual consumer — to keep
            // boot-time validation aligned with what passesAmountLimits() will accept later.
            try {
                Price::create($limits[$key]);
            } catch (\Throwable $e) {
                throw new \InvalidArgumentException(
                    "Payment gateway [{$gateway}] has invalid amount_limits: [{$key}] must be a numeric value Price::create() can parse, got ".var_export($limits[$key], true).'.'
                );
            }
        }

        if (isset($limits['min'], $limits['max']) && $limits['min'] > $limits['max']) {
            throw new \InvalidArgumentException("Payment gateway [{$gateway}] has invalid amount_limits: min ({$limits['min']}) cannot exceed max ({$limits['max']}).");
        }
    }

    protected function passesAmountLimits(string $gateway, PriceClass $amount): bool
    {
        $limits = $this->gateways[$gateway]['amount_limits'] ?? null;

        if (! $limits) {
            return true;
        }

        if (isset($limits['min']) && $amount->lessThan(Price::create($limits['min']))) {
            return false;
        }

        if (isset($limits['max']) && Price::create($limits['max'])->lessThan($amount)) {
            return false;
        }

        return true;
    }

    public function isAvailableFor(string $gateway, PriceClass $amount): bool
    {
        return $this->has($gateway) && $this->passesAmountLimits($gateway, $amount);
    }

    protected function resolve(string $name): PaymentInterface
    {
        if ($this->gateways[$name]['instance'] === null) {
            $instance = app($this->gateways[$name]['class']);
            $this->gateways[$name]['instance'] = $instance;

            // Resolve the label lazily if it wasn't set in config
            if ($this->gateways[$name]['label'] === null) {
                $this->gateways[$name]['label'] = $instance->label();
            }
        }

        return $this->gateways[$name]['instance'];
    }

    public function gateway(?string $name = null): PaymentInterface
    {
        if ($name === null) {
            $name = $this->defaultName;
        }

        if (! isset($this->gateways[$name])) {
            throw new \InvalidArgumentException("Payment gateway [{$name}] is not configured.");
        }

        return $this->resolve($name);
    }

    public function label(?string $name = null): string
    {
        $name = $name ?? $this->defaultName;

        if (! isset($this->gateways[$name])) {
            return $name ?? '';
        }

        // Resolve instance if label is still null
        if ($this->gateways[$name]['label'] === null) {
            $this->resolve($name);
        }

        return $this->gateways[$name]['label'];
    }

    public function all(): array
    {
        return collect($this->gateways)->mapWithKeys(function ($config, $name) {
            return [$name => $this->resolve($name)];
        })->all();
    }

    public function has(string $name): bool
    {
        return isset($this->gateways[$name]);
    }

    public function hasMultiple(): bool
    {
        return count($this->gateways) > 1;
    }

    public function forReservation(Reservation $reservation): PaymentInterface
    {
        return $this->gateway($reservation->payment_gateway);
    }

    public function calculateSurcharge(string $gateway, PriceClass $basePayment): PriceClass
    {
        $config = $this->gateways[$gateway]['surcharge'] ?? null;

        if (! $config) {
            return Price::create(0);
        }

        if ($config['type'] === 'percent') {
            return Price::create($basePayment->format())->percent($config['amount']);
        }

        if ($config['type'] === 'fixed') {
            return Price::create($config['amount']);
        }

        throw new \InvalidArgumentException("Invalid surcharge type [{$config['type']}] for payment gateway [{$gateway}].");
    }

    public function availableForFrontend(?PriceClass $amount = null): array
    {
        return collect($this->gateways)
            ->filter(fn ($config, $name) => $amount === null || $this->passesAmountLimits($name, $amount))
            ->map(function ($config, $name) {
                $instance = $this->resolve($name);

                return [
                    'name' => $name,
                    'label' => $this->gateways[$name]['label'],
                    'redirects' => $instance->redirectsForPayment(),
                    'surcharge' => $this->gateways[$name]['surcharge'] ?? null,
                ];
            })->values()->all();
    }
}
