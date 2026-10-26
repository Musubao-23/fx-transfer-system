<?php

namespace App\Transfer;

class PayoutService
{
    private $pdo;
    private $provider;
    private $logger;

    public function __construct($pdo, PayoutProvider $provider, TransactionLogger $logger)
    {
        $this->pdo = $pdo;
        $this->provider = $provider;
        $this->logger = $logger;
    }

    // Pays the receiver for a transfer that is 'processing'.
    // Returns ['status' => ..., 'message' => ...].
    public function process($transactionId)
    {
        $transactionId = (int) $transactionId;

        $this->pdo->beginTransaction();

        try {
            // Lock the row so two requests cannot pay the same transfer twice
            $stmt = $this->pdo->prepare(
                "SELECT t.id, t.status, t.amount_received,
                        t.receiver_name, t.receiver_phone, t.receiver_country,
                        tc.code AS target_code
                 FROM transactions t
                 JOIN currencies tc ON tc.id = t.target_currency_id
                 WHERE t.id = ?
                 FOR UPDATE"
            );
            $stmt->execute([$transactionId]);
            $t = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$t) {
                $this->pdo->rollBack();
                return ['status' => null, 'message' => 'Transfer not found.'];
            }

            if ($t['status'] !== 'processing') {
                $this->pdo->rollBack();
                return [
                    'status' => $t['status'],
                    'message' => 'This transfer is already ' . $t['status'] . '.',
                ];
            }

            $result = $this->provider->sendPayout(
                'TX-' . $transactionId,
                $t['amount_received'],
                $t['target_code'],
                [
                    'name' => $t['receiver_name'],
                    'phone' => $t['receiver_phone'],
                    'country' => $t['receiver_country'],
                ]
            );

            if ($result['success']) {
                $stmt = $this->pdo->prepare(
                    "UPDATE transactions
                     SET status = 'completed', payout_reference = ?, completed_at = NOW()
                     WHERE id = ?"
                );
                $stmt->execute([$result['payout_reference'], $transactionId]);

                $this->logger->log(
                    $transactionId,
                    'processing',
                    'completed',
                    'Payout sent (reference ' . $result['payout_reference'] . ')'
                );

                $final = 'completed';
                $message = 'Payment received and the money was sent to the receiver. Your transfer is complete.';
            } else {
                $stmt = $this->pdo->prepare("UPDATE transactions SET status = 'reversed' WHERE id = ?");
                $stmt->execute([$transactionId]);

                $this->logger->log(
                    $transactionId,
                    'processing',
                    'reversed',
                    'Payout failed: ' . substr((string) $result['message'], 0, 150) . '. The sender must be refunded.'
                );

                $final = 'reversed';
                $message = 'Your payment was received, but the payout to the receiver failed. '
                         . 'The transfer was reversed, and your payment must be refunded to you.';
            }

            $this->pdo->commit();
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($final === 'completed') {
            $this->createReceipt($transactionId);
        }

        return ['status' => $final, 'message' => $message];
    }

    // A receipt problem must never undo a completed transfer
    private function createReceipt($transactionId)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM receipts WHERE transaction_id = ? LIMIT 1");
            $stmt->execute([$transactionId]);

            if ($stmt->fetch()) {
                return;
            }

            $generator = new \App\Receipt\ReceiptGenerator($this->pdo);
            $generator->generateReceipt($transactionId);
        } catch (\Throwable $e) {
            error_log('Receipt creation failed: ' . $e->getMessage());
        }
    }
}