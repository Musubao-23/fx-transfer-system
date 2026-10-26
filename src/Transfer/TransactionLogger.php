<?php

namespace App\Transfer;

class TransactionLogger
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function log($transactionId, $previousStatus, $newStatus, $note = null)
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO transaction_logs (transaction_id, previous_status, new_status, note)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$transactionId, $previousStatus, $newStatus, $note]);
    }
}