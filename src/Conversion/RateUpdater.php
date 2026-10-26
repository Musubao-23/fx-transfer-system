<?php

namespace App\Conversion;

class RateUpdater
{
    private $pdo;
    private $fetcher;
    private $baseCode = 'USD';

    public function __construct($pdo, RateFetcher $fetcher)
    {
        $this->pdo = $pdo;
        $this->fetcher = $fetcher;
    }

    // Fetches only if the saved rates are older than $maxAgeMinutes (hourly cache)
    public function updateIfStale($maxAgeMinutes = 60)
    {
        $row = $this->pdo->query(
            "SELECT TIMESTAMPDIFF(MINUTE, MAX(fetched_at), NOW()) AS age FROM exchange_rates"
        )->fetch();

        if ($row['age'] === null || $row['age'] >= $maxAgeMinutes) {
            return $this->updateRates();
        }

        return 0;
    }

    // Always fetches and saves USD-based rates for every active currency
    public function updateRates()
    {
        $data = $this->fetcher->fetchRates($this->baseCode);

        $currencies = $this->pdo->query(
            "SELECT id, code FROM currencies WHERE is_active = 1"
        )->fetchAll();

        $baseId = null;
        foreach ($currencies as $c) {
            if ($c['code'] === $this->baseCode) {
                $baseId = $c['id'];
            }
        }

        if ($baseId === null) {
            throw new \Exception('USD is missing from the currencies table.');
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO exchange_rates (base_currency_id, target_currency_id, rate)
             VALUES (?, ?, ?)"
        );

        $saved = 0;
        $this->pdo->beginTransaction();

        try {
            foreach ($currencies as $c) {
                if ($c['code'] === $this->baseCode) {
                    continue;
                }
                if (!isset($data['rates'][$c['code']])) {
                    continue;
                }
                $insert->execute([$baseId, $c['id'], $data['rates'][$c['code']]]);
                $saved++;
            }
            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $saved;
    }
}