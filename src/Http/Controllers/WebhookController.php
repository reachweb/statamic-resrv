<?php

namespace Reach\StatamicResrv\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Models\Reservation;

class WebhookController extends Controller
{
    public function store(Request $request)
    {
        $payment = app(PaymentInterface::class);

        $payment->verifyPayment($request);

        ray($request->all());
    }
}
