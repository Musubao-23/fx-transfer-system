<?php

namespace App\History;

class TransactionHistory
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function getUserTransactions($userId)
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                id,
                sender_name,
                receiver_name,
                sender_country,
                receiver_country,
                amount_sent,
                source_currency_id,
                target_currency_id,
                rate_applied,
                amount_received,
                status,
                purpose_of_transfer,
                created_at
             FROM transactions
             WHERE user_id = ?
             ORDER BY created_at DESC"
        );

        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }
}