<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';

requireAdmin($pdo);

$statuses = ['pending', 'processing', 'completed', 'failed', 'reversed'];

$counts = $pdo->query(
    "SELECT status, COUNT(*) FROM transactions GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$totalTransfers = array_sum($counts);
$totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeCurrencies = (int) $pdo->query(
    "SELECT COUNT(*) FROM currencies WHERE is_active = 1"
)->fetchColumn();

$latest = $pdo->query(
    "SELECT t.id, t.created_at, t.status, t.amount_sent, t.amount_received,
            t.sender_name, t.receiver_name,
            sc.code AS source_code, tc.code AS target_code
     FROM transactions t
     JOIN currencies sc ON sc.id = t.source_currency_id
     JOIN currencies tc ON tc.id = t.target_currency_id
     ORDER BY t.id DESC
     LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
</head>
<body>

    <h1>Admin Dashboard</h1>

    <?php require __DIR__ . '/_nav.php'; ?>

    <p>
        <?= (int) $totalTransfers ?> transfer(s) |
        <?= $totalUsers ?> user(s) |
        <?= $activeCurrencies ?> active currencies
    </p>

    <?php if ((int) ($counts['reversed'] ?? 0) > 0): ?>
        <p><strong>Needs attention:</strong>
            <a href="transactions.php?status=reversed"><?= (int) $counts['reversed'] ?> reversed transfer(s)</a>
            where the sender must be refunded.</p>
    <?php endif; ?>

    <h2>Transfers by status</h2>
    <table border="1" cellpadding="6" cellspacing="0">
        <tr><th>Status</th><th>Count</th></tr>
        <?php foreach ($statuses as $s): ?>
            <tr>
                <td><a href="transactions.php?status=<?= $s ?>"><?= ucfirst($s) ?></a></td>
                <td><?= (int) ($counts[$s] ?? 0) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2>Latest transfers</h2>

    <?php if (empty($latest)): ?>
        <p>No transfers yet.</p>
    <?php else: ?>
        <table border="1" cellpadding="6" cellspacing="0">
            <tr>
                <th>ID</th><th>Date</th><th>Sender</th><th>Receiver</th>
                <th>Sent</th><th>Received</th><th>Status</th>
            </tr>
            <?php foreach ($latest as $t): ?>
                <tr>
                    <td><a href="transaction.php?id=<?= (int) $t['id'] ?>">#<?= (int) $t['id'] ?></a></td>
                    <td><?= htmlspecialchars($t['created_at']) ?></td>
                    <td><?= htmlspecialchars((string) $t['sender_name']) ?></td>
                    <td><?= htmlspecialchars((string) $t['receiver_name']) ?></td>
                    <td><?= number_format($t['amount_sent'], 2) ?> <?= htmlspecialchars($t['source_code']) ?></td>
                    <td><?= number_format($t['amount_received'], 2) ?> <?= htmlspecialchars($t['target_code']) ?></td>
                    <td><?= htmlspecialchars(strtoupper($t['status'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

</body>
</html>