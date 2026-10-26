<?php

namespace App\Transfer;

class SandboxGateway implements PaymentGateway
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function createPaymentLink($txRef, $amount, $currency, $redirectUrl, array $customer, $description)
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO sandbox_payments (tx_ref, amount, currency, description)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $txRef,
            number_format((float) $amount, 2, '.', ''),
            $currency,
            $description,
        ]);

        // The fake checkout page lives next to the callback page
        $base = substr($redirectUrl, 0, strrpos($redirectUrl, '/'));

        return $base . '/sandbox-checkout.php?ref=' . rawurlencode($txRef);
    }

    public function verifyTransaction($paymentId)
    {
        $stmt = $this->pdo->prepare(
            "SELECT tx_ref, amount, currency, status FROM sandbox_payments WHERE id = ? LIMIT 1"
        );
        $stmt->execute([(int) $paymentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            throw new \Exception('Sandbox payment not found.');
        }

        return [
            'status' => $row['status'],
            'tx_ref' => $row['tx_ref'],
            'amount' => (float) $row['amount'],
            'currency' => $row['currency'],
        ];
    }

    public function getPaymentByRef($txRef)
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, tx_ref, amount, currency, description, status
             FROM sandbox_payments WHERE tx_ref = ? LIMIT 1"
        );
        $stmt->execute([$txRef]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    // Ends a simulated payment. Returns its id, or null if it was already finished.
    public function finishPayment($txRef, $newStatus)
    {
        if (!in_array($newStatus, ['successful', 'failed', 'cancelled'], true)) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE sandbox_payments SET status = ? WHERE tx_ref = ? AND status = 'initiated'"
        );
        $stmt->execute([$newStatus, $txRef]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        $payment = $this->getPaymentByRef($txRef);

        return $payment ? (int) $payment['id'] : null;
    }
}