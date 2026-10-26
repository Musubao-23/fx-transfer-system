<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';

requireAdmin($pdo);

$validStatuses = ['pending', 'processing', 'completed', 'failed', 'reversed'];

$filter = $_GET['status'] ?? '';
if (!is_string($filter) || !in_array($filter, $validStatuses, true)) {
    $filter = '';
}

$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = $filter !== '' ? 'WHERE t.status = ?' : '';
$params = $filter !== '' ? [$filter] : [];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions t $where");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = $pdo->prepare(
    "SELECT t.id, t.created_at, t.status, t.amount_sent, t.amount_received, t.rate_applied,
            t.sender_name, t.receiver_name, t.receiver_country,
            sc.code AS source_code, tc.code AS target_code
     FROM transactions t
     JOIN currencies sc ON sc.id = t.source_currency_id
     JOIN currencies tc ON tc.id = t.target_currency_id
     $where
     ORDER BY t.id DESC
     LIMIT " . (int) $perPage . " OFFSET " . (int) $offset
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$query = $filter !== '' ? 'status=' . urlencode($filter) . '&amp;' : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Transfers</title>
</head>
<body>

    <h1>All Transfers</h1>

    <?php require __DIR__ . '/_nav.php'; ?>

    <p>Filter:
        <a href="transactions.php">All</a>
        <?php foreach ($validStatuses as $s): ?>
            | <a href="transactions.php?status=<?= $s ?>"><?= ucfirst($s) ?></a>
        <?php endforeach; ?>
    </p>

    <p>
        <?= $total ?> transfer(s)<?= $filter !== '' ? ' with status ' . htmlspecialchars($filter) : '' ?>.
        Page <?= $page ?> of <?= $pages ?>.
    </p>

    <?php if (empty($rows)): ?>

        <p>Nothing to show.</p>

    <?php else: ?>

        <table border="1" cellpadding="6" cellspacing="0">
            <tr>
                <th>ID</th><th>Date</th><th>Sender</th><th>Receiver</th><th>Country</th>
                <th>Sent</th><th>Received</th><th>Rate</th><th>Status</th>
            </tr>
            <?php foreach ($rows as $t): ?>
                <tr>
                    <td><a href="transaction.php?id=<?= (int) $t['id'] ?>">#<?= (int) $t['id'] ?></a></td>
                    <td><?= htmlspecialchars($t['created_at']) ?></td>
                    <td><?= htmlspecialchars((string) $t['sender_name']) ?></td>
                    <td><?= htmlspecialchars((string) $t['receiver_name']) ?></td>
                    <td><?= htmlspecialchars((string) $t['receiver_country']) ?></td>
                    <td><?= number_format($t['amount_sent'], 2) ?> <?= htmlspecialchars($t['source_code']) ?></td>
                    <td><?= number_format($t['amount_received'], 2) ?> <?= htmlspecialchars($t['target_code']) ?></td>
                    <td><?= htmlspecialchars(rtrim(rtrim(number_format($t['rate_applied'], 10, '.', ''), '0'), '.')) ?></td>
                    <td><?= htmlspecialchars(strtoupper($t['status'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <p>
            <?php if ($page > 1): ?>
                <a href="transactions.php?<?= $query ?>page=<?= $page - 1 ?>">Previous</a>
            <?php endif; ?>
            <?php if ($page < $pages): ?>
                <a href="transactions.php?<?= $query ?>page=<?= $page + 1 ?>">Next</a>
            <?php endif; ?>
        </p>

    <?php endif; ?>

</body>
</html>