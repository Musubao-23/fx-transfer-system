<?php

namespace App\Conversion;

class RateFetcher
{
    private $apiUrl = 'https://open.er-api.com/v6/latest/';

    public function fetchRates($baseCurrency = 'USD')
    {
        $url = $this->apiUrl . strtoupper($baseCurrency);

        $context = stream_context_create([
            'http' => ['timeout' => 10]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new \Exception('Could not reach the exchange rate service.');
        }

        $data = json_decode($response, true);

        if (!$data || ($data['result'] ?? '') !== 'success') {
            throw new \Exception('Exchange rate service returned an invalid response.');
        }

        return [
            'base' => $data['base_code'],
            'updated_at' => $data['time_last_update_utc'],
            'rates' => $data['rates'],
        ];
    }
}