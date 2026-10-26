<?php

namespace App\Transfer;

require_once __DIR__ . '/../../config/env.php';

class FlutterwaveClient implements PaymentGateway
{
    private $secretKey;
    private $baseUrl;

    public function __construct()
    {
        $this->secretKey = env('FLW_SECRET_KEY');
        $this->baseUrl = rtrim(env('FLW_BASE_URL', 'https://api.flutterwave.com/v3'), '/');

        if (!$this->secretKey) {
            throw new \Exception('FLW_SECRET_KEY is missing from the .env file.');
        }

        // This prototype must never touch real money
        if (strpos($this->secretKey, 'TEST') === false) {
            throw new \Exception('Only Flutterwave TEST keys are allowed in this prototype.');
        }
    }

    private function request($method, $path, $payload = null)
    {
        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $options = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ];

        if ($payload !== null) {
            $options['http']['content'] = json_encode($payload);
        }

        $response = @file_get_contents(
            $this->baseUrl . $path,
            false,
            stream_context_create($options)
        );

        if ($response === false) {
            throw new \Exception('Could not reach Flutterwave.');
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new \Exception('Flutterwave returned an unreadable response.');
        }

        return $data;
    }

    // Returns the address of Flutterwave's hosted checkout page
    public function createPaymentLink($txRef, $amount, $currency, $redirectUrl, array $customer, $description)
    {
        $data = $this->request('POST', '/payments', [
            'tx_ref' => $txRef,
            'amount' => number_format((float) $amount, 2, '.', ''),
            'currency' => $currency,
            'redirect_url' => $redirectUrl,
            'customer' => [
                'email' => $customer['email'],
                'name' => $customer['name'],
                'phonenumber' => (string) $customer['phonenumber'],
            ],
            'customizations' => [
                'title' => 'FX Transfer System',
                'description' => $description,
            ],
        ]);

        if (($data['status'] ?? '') !== 'success' || empty($data['data']['link'])) {
            throw new \Exception('Flutterwave: ' . ($data['message'] ?? 'could not create the payment link'));
        }

        return $data['data']['link'];
    }

    // Asks Flutterwave for the real details of a payment
    public function verifyTransaction($flutterwaveId)
    {
        $data = $this->request('GET', '/transactions/' . rawurlencode((string) $flutterwaveId) . '/verify');

        if (($data['status'] ?? '') !== 'success' || empty($data['data'])) {
            throw new \Exception('Flutterwave: ' . ($data['message'] ?? 'verification failed'));
        }

        return $data['data'];
    }
}