<?php

namespace App\Conversion;

class CurrencyConverter
{
    private $pdo;
    private $usdId = null;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    private function getUsdId()
    {
        if ($this->usdId === null) {
            $stmt = $this->pdo->prepare("SELECT id FROM currencies WHERE code = 'USD'");
            $stmt->execute();
            $row = $stmt->fetch();

            if (!$row) {
                throw new \Exception('USD is missing from the currencies table.');
            }
            $this->usdId = (int) $row['id'];
        }
        return $this->usdId;
    }

    // Latest saved rate: how many units of this currency equal 1 USD
    private function getLatestUsdRate($currencyId)
    {
        if ($currencyId == $this->getUsdId()) {
            return ['rate' => 1, 'age_minutes' => 0];
        }

        $stmt = $this->pdo->prepare(
            "SELECT rate, TIMESTAMPDIFF(MINUTE, fetched_at, NOW()) AS age_minutes
             FROM exchange_rates
             WHERE base_currency_id = ? AND target_currency_id = ?
             ORDER BY fetched_at DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute([$this->getUsdId(), $currencyId]);

        return $stmt->fetch();
    }

    // Rate from one currency to another, calculated through USD
    public function getRate($baseCurrencyId, $targetCurrencyId, $maxAgeMinutes = 120)
    {
        if ($baseCurrencyId == $targetCurrencyId) {
            return 1.0;
        }

        $base = $this->getLatestUsdRate($baseCurrencyId);
        $target = $this->getLatestUsdRate($targetCurrencyId);

        if (!$base || !$target) {
            return null;
        }

        if ($base['age_minutes'] > $maxAgeMinutes || $target['age_minutes'] > $maxAgeMinutes) {
            throw new \Exception('Exchange rates are out of date. Please refresh them first.');
        }

        return (float) $target['rate'] / (float) $base['rate'];
    }

    public function convert($amount, $rate)
    {
        return round($amount * $rate, 2);
    }

    // A full quote: amount, rate, result, and the time until which the rate is locked
    public function quote($amount, $baseCurrencyId, $targetCurrencyId, $lockMinutes = 10)
    {
        $rate = $this->getRate($baseCurrencyId, $targetCurrencyId);

        if ($rate === null) {
            throw new \Exception('No exchange rate available for this currency pair.');
        }

        return [
            'amount_sent' => round($amount, 2),
            'rate' => $rate,
            'amount_received' => $this->convert($amount, $rate),
            'rate_locked_until' => date('Y-m-d H:i:s', time() + $lockMinutes * 60),
        ];
    }
}