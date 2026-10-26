<?php

namespace App\Transfer;

require_once __DIR__ . '/../../config/env.php';

class PaymentService
{
    private $pdo;
    private $client;
    private $logger;

    public function __construct($pdo, PaymentGateway $client, TransactionLogger $logger)    {
        $this->pdo = $pdo;
        $this->client = $client;
        $this->logger = $logger;
    }

    // Changes the status only if the transfer is still in the expected one, and logs it.
    private function changeStatus($transactionId, $from, $to, $note, $flutterwaveId = null)
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                "UPDATE transactions
                 SET status = ?, flutterwave_transaction_id = COALESCE(?, flutterwave_transaction_id)
                 WHERE id = ? AND status = ?"
            );
            $stmt->execute([$to, $flutterwaveId, $transactionId, $from]);

            if ($stmt->rowCount() === 0) {
                $this->pdo->rollBack();
                return false;
            }

            $this->logger->log($transactionId, $from, $to, $note);
            $this->pdo->commit();

            return true;
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // Creates the checkout link for a pending transfer that belongs to this user
    public function startPayment($transactionId, $userId)
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.id, t.amount_sent, t.status, t.receiver_name,
                    (t.locked_rate_expires_at > NOW()) AS lock_active,
                    sc.code AS source_code,
                    u.full_name, u.email, u.phone
             FROM transactions t
             JOIN currencies sc ON sc.id = t.source_currency_id
             JOIN users u ON u.id = t.user_id
             WHERE t.id = ? AND t.user_id = ?
             LIMIT 1"
        );
        $stmt->execute([$transactionId, $userId]);
        $t = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$t) {
            throw new \InvalidArgumentException('Transfer not found.');
        }
        if ($t['status'] !== 'pending') {
            throw new \InvalidArgumentException('This transfer is already ' . $t['status'] . '.');
        }
        if (!(int) $t['lock_active']) {
            $this->changeStatus((int) $t['id'], 'pending', 'failed', 'Rate lock expired before payment');
            throw new \InvalidArgumentException('The locked rate expired. Please create a new transfer.');
        }

        $txRef = 'FX-' . $t['id'] . '-' . bin2hex(random_bytes(6));

        $stmt = $this->pdo->prepare(
            "UPDATE transactions SET flutterwave_reference = ? WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$txRef, $t['id']]);

        return $this->client->createPaymentLink(
            $txRef,
            $t['amount_sent'],
            $t['source_code'],
            rtrim(env('APP_URL'), '/') . '/payment-callback.php',
            [
                'email' => $t['email'],
                'name' => $t['full_name'],
                'phonenumber' => $t['phone'],
            ],
            'Transfer #' . $t['id'] . ' to ' . $t['receiver_name']
        );
    }

    // Handles the customer coming back from Flutterwave.
    // Returns ['transaction_id', 'status', 'message'].
    public function handleCallback($userId, $txRef, $flutterwaveId, $returnedStatus)
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.id, t.amount_sent, t.status, sc.code AS source_code
             FROM transactions t
             JOIN currencies sc ON sc.id = t.source_currency_id
             WHERE t.flutterwave_reference = ? AND t.user_id = ?
             LIMIT 1"
        );
        $stmt->execute([$txRef, $userId]);
        $t = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$t) {
            return [
                'transaction_id' => null,
                'status' => null,
                'message' => 'We could not find this payment.',
            ];
        }

        $id = (int) $t['id'];

        // Refreshing this page must never process a payment twice
        if ($t['status'] !== 'pending') {
            return [
                'transaction_id' => $id,
                'status' => $t['status'],
                'message' => 'This transfer is already ' . $t['status'] . '.',
            ];
        }

        if ($returnedStatus === 'cancelled') {
            $this->changeStatus($id, 'pending', 'failed', 'Payment cancelled by the sender');
            return [
                'transaction_id' => $id,
                'status' => 'failed',
                'message' => 'The payment was cancelled.',
            ];
        }

        if (!in_array($returnedStatus, ['successful', 'completed'], true) || !ctype_digit($flutterwaveId)) {
            $this->changeStatus(
                $id,
                'pending',
                'failed',
                'Payment was not successful (' . substr($returnedStatus, 0, 30) . ')'
            );
            return [
                'transaction_id' => $id,
                'status' => 'failed',
                'message' => 'The payment was not successful.',
            ];
        }

        // Never trust the address bar: ask Flutterwave
        try {
            $data = $this->client->verifyTransaction($flutterwaveId);
        } catch (\Exception $e) {
            error_log('Verification failed: ' . $e->getMessage());
            return [
                'transaction_id' => $id,
                'status' => 'pending',
                'message' => 'We could not confirm your payment yet. Please refresh this page in a moment.',
            ];
        }

        $valid = ($data['status'] ?? '') === 'successful'
              && ($data['tx_ref'] ?? '') === $txRef
              && ($data['currency'] ?? '') === $t['source_code']
              && (float) ($data['amount'] ?? 0) + 0.01 >= (float) $t['amount_sent'];

        if (!$valid) {
            $this->changeStatus($id, 'pending', 'failed', 'Payment could not be verified');
            return [
                'transaction_id' => $id,
                'status' => 'failed',
                'message' => 'We could not verify your payment. No transfer will be made.',
            ];
        }

        $this->changeStatus(
            $id,
            'pending',
            'processing',
            'Payment received and verified (Flutterwave ID ' . $flutterwaveId . ')',
            $flutterwaveId
        );

        return [
            'transaction_id' => $id,
            'status' => 'processing',
            'message' => 'Payment received. Your transfer is now being processed.',
        ];
    }
}