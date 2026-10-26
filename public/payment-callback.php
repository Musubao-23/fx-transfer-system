<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

use App\Transfer\GatewayFactory;
use App\Transfer\PaymentService;
use App\Transfer\PayoutService;
use App\Transfer\SandboxPayout;
use App\Transfer\TransactionLogger;

requireLogin();

$userId = (int) $_SESSION['user_id'];

$status = $_GET['status'] ?? '';
$txRef = $_GET['tx_ref'] ?? '';
$gatewayPaymentId = $_GET['transaction_id'] ?? '';

$result = [
    'transaction_id' => null,
    'status' => null,
    'message' => 'There is no payment to show.',
];

if (is_string($txRef) && $txRef !== '') {
    try {
        $logger = new TransactionLogger($pdo);

        $payments = new PaymentService($pdo, GatewayFactory::make($pdo), $logger);

        $result = $payments->handleCallback(
            $userId,
            $txRef,
            is_string($gatewayPaymentId) ? $gatewayPaymentId : '',
            is_string($status) ? $status : ''
        );

        // A verified payment is paid out to the receiver straight away
        if ($result['status'] === 'processing') {
            $payouts = new PayoutService($pdo, new SandboxPayout(), $logger);
            $payout = $payouts->process($result['transaction_id']);

            $result['status'] = $payout['status'];
            $result['message'] = $payout['message'];
        }
    } catch (\Exception $e) {
        error_log('Payment callback failed: ' . $e->getMessage());
        $result['message'] = 'We could not check your payment right now. Please open your history and try again later.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment result</title>
</head>
<body>

    <?php require __DIR__ . '/_banner.php'; ?>

    <h1>Payment result</h1>

    <p><?= htmlspecialchars($result['message']) ?></p>

    <?php if ($result['transaction_id'] !== null): ?>
        <p>Transfer #<?= (int) $result['transaction_id'] ?>
           | Status: <strong><?= htmlspecialchars(strtoupper((string) $result['status'])) ?></strong></p>

        <?php if ($result['status'] === 'completed'): ?>
            <p><a href="download-receipt.php?id=<?= (int) $result['transaction_id'] ?>">Download receipt</a></p>
        <?php endif; ?>
    <?php endif; ?>

    <p><a href="history.php">View history</a> | <a href="transfer.php">New transfer</a></p>

</body>
</html>