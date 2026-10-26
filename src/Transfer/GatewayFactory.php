<?php

namespace App\Transfer;

require_once __DIR__ . '/../../config/env.php';

class GatewayFactory
{
    // Picks the payment gateway named in the .env file
    public static function make($pdo)
    {
        if (env('PAYMENT_GATEWAY', 'flutterwave') === 'sandbox') {
            return new SandboxGateway($pdo);
        }

        return new FlutterwaveClient();
    }
}