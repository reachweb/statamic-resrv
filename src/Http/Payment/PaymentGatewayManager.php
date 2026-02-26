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
                $instance = app($config['class']);
                $this->gateways[$name] = [
                    'instance' => $instance,
                    'label' => $config['label'] ?? $instance->label(),
                ];
            }
            $this->defaultName = array_key_first($this->gateways);

            return;
        }

        // Fallback to single gateway config
        $instance = app(PaymentInterface::class);
        $name = $instance->name();
        $this->gateways[$name] = [
            'instance' => $instance,
            'label' => $instance->label(),
        ];
        $this->defaultName = $name;
    }

    public function gateway(?string $name = null): PaymentInterface
    {
        if ($name === null) {
            $name = $this->defaultName;
        }

        if (! isset($this->gateways[$name])) {
            throw new \InvalidArgumentException("Payment gateway [{$name}] is not configured.");
        }

        return $this->gateways[$name]['instance'];
    }

    public function label(?string $name = null): string
    {
        $name = $name ?? $this->defaultName;

        return $this->gateways[$name]['label'] ?? $name;
    }

    public function all(): array
    {
        return collect($this->gateways)->mapWithKeys(function ($config, $name) {
            return [$name => $config['instance']];
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
            return [
                'name' => $name,
                'label' => $config['label'],
                'redirects' => $config['instance']->redirectsForPayment(),
            ];
        })->values()->all();
    }
}
