<?php

namespace App\Transfer;

use App\Conversion\CurrencyConverter;

class TransferService
{
    // Largest single transfer, measured in US dollars
    const MAX_TRANSFER_USD = 500;

    // How long a quoted rate stays locked (10 minutes)
    const LOCK_SECONDS = 600;

    private $pdo;
    private $converter;
    private $logger;

    public function __construct($pdo, CurrencyConverter $converter, TransactionLogger $logger)
    {
        $this->pdo = $pdo;
        $this->converter = $converter;
        $this->logger = $logger;
    }

    private function getUsdId()
    {
        $stmt = $this->pdo->prepare("SELECT id FROM currencies WHERE code = 'USD'");
        $stmt->execute();
        $row = $stmt->fetch();

        if (!$row) {
            throw new \Exception('USD is missing from the currencies table.');
        }

        return (int) $row['id'];
    }

    // Checks the input and builds a quote.
    // Throws \InvalidArgumentException with a friendly message for bad input.
    public function createQuote($amount, $sourceId, $targetId)
    {
        if (!is_finite($amount) || $amount <= 0) {
            throw new \InvalidArgumentException('Please enter an amount greater than zero.');
        }
        if ($sourceId === $targetId) {
            throw new \InvalidArgumentException('Please select two different currencies.');
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, code FROM currencies WHERE is_active = 1 AND id IN (?, ?)"
        );
        $stmt->execute([$sourceId, $targetId]);
        $codes = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        if (count($codes) !== 2) {
            throw new \InvalidArgumentException('Please choose two valid currencies.');
        }

        $rate = $this->converter->getRate($sourceId, $targetId);
        $toUsd = $this->converter->getRate($sourceId, $this->getUsdId());

        if ($rate === null || $toUsd === null) {
            throw new \InvalidArgumentException('No exchange rate is available for this currency pair.');
        }

        $amount = round($amount, 2);
        $usdEquivalent = round($amount * $toUsd, 2);

        if ($usdEquivalent > self::MAX_TRANSFER_USD) {
            throw new \InvalidArgumentException(
                'The maximum for one transfer is the equivalent of ' . self::MAX_TRANSFER_USD . ' USD.'
            );
        }

        $received = $this->converter->convert($amount, $rate);

        if ($received <= 0) {
            throw new \InvalidArgumentException('That amount is too small to convert.');
        }

        return [
            'amount_sent' => $amount,
            'source_currency_id' => $sourceId,
            'target_currency_id' => $targetId,
            'source_code' => $codes[$sourceId],
            'target_code' => $codes[$targetId],
            'rate' => $rate,
            'amount_received' => $received,
            'usd_equivalent' => $usdEquivalent,
        ];
    }

    // Saves the transfer as 'pending' and logs it.
    // $secondsLeft is what remains of the 10-minute rate lock.
    public function createTransfer($userId, array $quote, array $details, $secondsLeft)
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO transactions (
                    user_id, sender_name, sender_phone, sender_country,
                    receiver_name, receiver_phone, receiver_country,
                    amount_sent, source_currency_id, target_currency_id,
                    rate_applied, amount_received, locked_rate_expires_at,
                    status, purpose_of_transfer
                 ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    DATE_ADD(NOW(), INTERVAL " . (int) $secondsLeft . " SECOND),
                    'pending', ?
                 )"
            );

            $stmt->execute([
                $userId,
                $details['sender_name'],
                $details['sender_phone'],
                $details['sender_country'],
                $details['receiver_name'],
                $details['receiver_phone'],
                $details['receiver_country'],
                $quote['amount_sent'],
                $quote['source_currency_id'],
                $quote['target_currency_id'],
                number_format($quote['rate'], 10, '.', ''),
                $quote['amount_received'],
                $details['purpose'],
            ]);

            $transactionId = (int) $this->pdo->lastInsertId();

            $this->logger->log($transactionId, null, 'pending', 'Transfer created, waiting for payment');

            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $transactionId;
    }
}