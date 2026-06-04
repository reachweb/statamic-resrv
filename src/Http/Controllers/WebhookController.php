<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;

class WebhookController extends Controller
{
    public function index(?string $gateway = null)
    {
        $payment = $this->resolveGateway($gateway);

        return response()->json($payment->verifyWebhook());
    }

    public function store(Request $request, ?string $gateway = null)
    {
        $payment = $this->resolveGateway($gateway);

        return $payment->verifyPayment($request);
    }

    protected function resolveGateway(?string $gateway): PaymentInterface
    {
        $manager = app(PaymentGatewayManager::class);

        if ($gateway) {
            try {
                return $manager->gateway($gateway);
            } catch (\InvalidArgumentException $e) {
                abort(404);
            }
        }

        // Multi-gateway deployments configure a per-gateway webhook URL (/resrv/api/webhook/{gateway});
        // the bare URL can't be routed to a specific provider, so reject it rather than silently hand
        // it to the default gateway (which would only fail signature verification anyway).
        if ($manager->hasMultiple()) {
            abort(404);
        }

        return app(PaymentInterface::class);
    }
}
