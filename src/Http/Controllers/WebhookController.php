<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;

class WebhookController extends Controller
{
    public function index()
    {
        $payment = app(PaymentInterface::class);

        return response()->json($payment->verifyWebhook());
    }

    public function store(Request $request)
    {
        $payment = app(PaymentInterface::class);

        $payment->verifyPayment($request);
    }
}
