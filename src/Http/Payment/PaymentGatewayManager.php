<?php

namespace Reach\StatamicResrv\Http\Payment;

use Reach\StatamicResrv\Models\Reservation;

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
                $this->gateways[$name] = [
                    'class' => $config['class'],
                    'label' => $config['label'] ?? null,
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
            'instance' => $instance,
        ];
        $this->defaultName = $name;
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

    public function hasMultiple(): bool
    {
        return count($this->gateways) > 1;
    }

    public function forReservation(Reservation $reservation): PaymentInterface
    {
        return $this->gateway($reservation->payment_gateway);
    }

    public function availableForFrontend(): array
    {
        return collect($this->gateways)->map(function ($config, $name) {
            $instance = $this->resolve($name);

            return [
                'name' => $name,
                'label' => $this->gateways[$name]['label'],
                'redirects' => $instance->redirectsForPayment(),
            ];
        })->values()->all();
    }
}
