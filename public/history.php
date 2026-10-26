<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

use App\History\TransactionHistory;

requireLogin();

$history = new TransactionHistory($pdo);

$transactions = $history->getUserTransactions($_SESSION['user_id']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            margin: 0;
            padding: 30px;
        }

        .container {
            max-width: 1100px;
            margin: auto;
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        h1 {
            text-align: center;
            margin-bottom: 25px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #0B5ED7;
            color: white;
        }

        tr:hover {
            background: #f5f5f5;
        }

        .status {
            font-weight: bold;
            text-transform: uppercase;
        }

        .empty {
            text-align: center;
            padding: 30px;
            color: #666;
        }
    </style>
</head>

<body>

    <?php require __DIR__ . '/_banner.php'; ?>

<div class="container">

    <h1>Transaction History</h1>
          <p style="text-align:right">
        Signed in as <?= htmlspecialchars($_SESSION['full_name']) ?>
               | <a href="transfer.php">New transfer</a>
        | <a href="account.php">Account</a>
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            | <a href="admin/index.php">Admin</a>
        <?php endif; ?>
        | <a href="logout.php">Log out</a>
    </p>
    <?php if (empty($transactions)): ?>

        <div class="empty">
            No transactions found.
        </div>

    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Sender</th>
                    <th>Receiver</th>
                    <th>Amount Sent</th>
                    <th>Amount Received</th>
                    <th>Rate</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($transactions as $transaction): ?>

                <tr>
                    <td><?= htmlspecialchars($transaction['id']) ?></td>

                    <td><?= htmlspecialchars($transaction['sender_name']) ?></td>

                    <td><?= htmlspecialchars($transaction['receiver_name']) ?></td>

                    <td>
                        <?= number_format($transaction['amount_sent'], 2) ?>
                    </td>

                    <td>
                        <?= number_format($transaction['amount_received'], 2) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($transaction['rate_applied']) ?>
                    </td>

                                                           <td class="status">
                        <?= htmlspecialchars($transaction['status']) ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($transaction['created_at']) ?>
                    </td>
                </tr>

            <?php endforeach; ?>

            </tbody>
        </table>

    <?php endif; ?>

</div>

</body>
</html>