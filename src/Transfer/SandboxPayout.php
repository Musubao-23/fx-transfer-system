<?php

namespace App\Transfer;

class SandboxPayout implements PayoutProvider
{
    public function sendPayout($reference, $amount, $currency, array $receiver)
    {
        // Simulation: a receiver phone number ending in 0000 makes the payout fail
        if (substr((string) $receiver['phone'], -4) === '0000') {
            return [
                'success' => false,
                'payout_reference' => null,
                'message' => 'Simulated payout failure',
            ];
        }

        return [
            'success' => true,
            'payout_reference' => 'PO-' . strtoupper(bin2hex(random_bytes(5))),
            'message' => 'Simulated payout sent',
        ];
    }
}