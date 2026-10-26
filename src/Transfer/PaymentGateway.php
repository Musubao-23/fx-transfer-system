<?php

namespace App\Transfer;

interface PaymentGateway
{
    public function createPaymentLink($txRef, $amount, $currency, $redirectUrl, array $customer, $description);

    public function verifyTransaction($transactionId);
}