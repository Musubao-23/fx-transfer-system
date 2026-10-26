<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

use App\Transfer\GatewayFactory;
use App\Transfer\PaymentService;
use App\Transfer\TransactionLogger;

requireLogin();

$userId = (int) $_SESSION['user_id'];
$transactionId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

// Detailed errors are shown only when the page is opened on this same computer
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!checkCsrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        try {
            $payments = new PaymentService(
                $pdo,
                GatewayFactory::make($pdo),
                new TransactionLogger($pdo)
            );
            $link = $payments->startPayment($transactionId, $userId);

            header('Location: ' . $link);
            exit;
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (\Exception $e) {
            error_log('Payment start failed: ' . $e->getMessage());
            $error = 'We could not start the payment right now. Please try again.';
            if ($isLocal) {
                $error .= ' (' . $e->getMessage() . ')';
            }
        }
    }
}

$stmt = $pdo->prepare(
    "SELECT t.id, t.amount_sent, t.amount_received, t.rate_applied, t.status,
            t.receiver_name, t.receiver_country,
            (t.locked_rate_expires_at > NOW()) AS lock_active,
            sc.code AS source_code, tc.code AS target_code
     FROM transactions t
     JOIN currencies sc ON sc.id = t.source_currency_id
     JOIN currencies tc ON tc.id = t.target_currency_id
     WHERE t.id = ? AND t.user_id = ?
     LIMIT 1"
);
$stmt->execute([$transactionId, $userId]);
$transfer = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay for your transfer</title>
</head>
<body>

    <?php require __DIR__ . '/_banner.php'; ?>

    <h1>Pay for your transfer</h1>

    <p>
        Signed in as <?= htmlspecialchars($_SESSION['full_name']) ?>
        | <a href="history.php">History</a>
        | <a href="transfer.php">New transfer</a>
        | <a href="logout.php">Log out</a>
    </p>

    <?php if ($error): ?>
        <p><strong>Error:</strong> <?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if (!$transfer): ?>

        <p>Transfer not found.</p>

    <?php else: ?>

        <p>Transfer #<?= (int) $transfer['id'] ?> to
           <?= htmlspecialchars($transfer['receiver_name']) ?>
           (<?= htmlspecialchars($transfer['receiver_country']) ?>)</p>
        <p>You pay:
            <strong><?= number_format($transfer['amount_sent'], 2) ?> <?= htmlspecialchars($transfer['source_code']) ?></strong></p>
        <p>Receiver gets:
            <strong><?= number_format($transfer['amount_received'], 2) ?> <?= htmlspecialchars($transfer['target_code']) ?></strong></p>
        <p>Status: <strong><?= htmlspecialchars(strtoupper($transfer['status'])) ?></strong></p>

        <?php if ($transfer['status'] !== 'pending'): ?>

            <p>This transfer is no longer waiting for payment.</p>

        <?php elseif (!(int) $transfer['lock_active']): ?>

            <p>The locked rate has expired.
               Please <a href="transfer.php">create a new transfer</a>.</p>

        <?php else: ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int) $transfer['id'] ?>">
                <button type="submit">Pay now</button>
            </form>

        <?php endif; ?>

    <?php endif; ?>

</body>
</html>