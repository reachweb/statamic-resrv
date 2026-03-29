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

        $payment->verifyPayment($request);
    }

    protected function resolveGateway(?string $gateway): PaymentInterface
    {
        if ($gateway) {
            try {
                return app(PaymentGatewayManager::class)->gateway($gateway);
            } catch (\InvalidArgumentException $e) {
                abort(404);
            }
        }

        return app(PaymentInterface::class);
    }
}
