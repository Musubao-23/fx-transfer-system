<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';

requireAdmin($pdo);

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT t.*, sc.code AS source_code, tc.code AS target_code, u.email AS user_email
     FROM transactions t
     JOIN currencies sc ON sc.id = t.source_currency_id
     JOIN currencies tc ON tc.id = t.target_currency_id
     LEFT JOIN users u ON u.id = t.user_id
     WHERE t.id = ?
     LIMIT 1"
);
$stmt->execute([$id]);
$t = $stmt->fetch(PDO::FETCH_ASSOC);

$logs = [];
$receipt = null;
$details = [];

if ($t) {
    $stmt = $pdo->prepare(
        "SELECT previous_status, new_status, note, changed_at
         FROM transaction_logs WHERE transaction_id = ? ORDER BY id"
    );
    $stmt->execute([$id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT receipt_number FROM receipts WHERE transaction_id = ? LIMIT 1");
    $stmt->execute([$id]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

    $details = [
        'Transfer' => '#' . $t['id'],
        'Status' => strtoupper($t['status']),
        'Created' => $t['created_at'],
        'Account' => $t['user_email'] ?? 'guest',
        'Sender' => $t['sender_name'] . ' (' . $t['sender_phone'] . ', ' . $t['sender_country'] . ')',
        'Receiver' => $t['receiver_name'] . ' (' . $t['receiver_phone'] . ', ' . $t['receiver_country'] . ')',
        'Purpose' => $t['purpose_of_transfer'],
        'Amount sent' => number_format($t['amount_sent'], 2) . ' ' . $t['source_code'],
        'Amount received' => number_format($t['amount_received'], 2) . ' ' . $t['target_code'],
        'Rate applied' => rtrim(rtrim(number_format($t['rate_applied'], 10, '.', ''), '0'), '.'),
        'Rate locked until' => $t['locked_rate_expires_at'],
        'Payment reference' => $t['flutterwave_reference'] ?? '-',
        'Payment ID' => $t['flutterwave_transaction_id'] ?? '-',
        'Payout reference' => $t['payout_reference'] ?? '-',
        'Completed at' => $t['completed_at'] ?? '-',
        'Receipt' => $receipt ? $receipt['receipt_number'] : '-',
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer details</title>
</head>
<body>

    <h1>Transfer details</h1>

    <?php require __DIR__ . '/_nav.php'; ?>

    <?php if (!$t): ?>

        <p>Transfer not found. <a href="transactions.php">Back to the list</a></p>

    <?php else: ?>

        <table border="1" cellpadding="6" cellspacing="0">
            <?php foreach ($details as $label => $value): ?>
                <tr>
                    <th align="left"><?= htmlspecialchars($label) ?></th>
                    <td><?= htmlspecialchars((string) $value) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h2>Status log</h2>

        <?php if (empty($logs)): ?>
            <p>No log entries.</p>
        <?php else: ?>
            <table border="1" cellpadding="6" cellspacing="0">
                <tr><th>Time</th><th>From</th><th>To</th><th>Note</th></tr>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= htmlspecialchars($log['changed_at']) ?></td>
                        <td><?= htmlspecialchars((string) ($log['previous_status'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars($log['new_status']) ?></td>
                        <td><?= htmlspecialchars((string) $log['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

        <p><a href="transactions.php">Back to the list</a></p>

    <?php endif; ?>

</body>
</html>