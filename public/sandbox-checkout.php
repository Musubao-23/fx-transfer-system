<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/env.php';

use App\Transfer\SandboxGateway;

requireLogin();

// The simulator works only when it is switched on in the .env file
if (env('PAYMENT_GATEWAY', 'flutterwave') !== 'sandbox') {
    http_response_code(404);
    exit('Not available.');
}

$gateway = new SandboxGateway($pdo);

$txRef = $_GET['ref'] ?? ($_POST['ref'] ?? '');
$payment = is_string($txRef) ? $gateway->getPaymentByRef($txRef) : null;

$callbackUrl = rtrim(env('APP_URL'), '/') . '/payment-callback.php';
$error = null;

if ($payment && $payment['status'] === 'initiated' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!checkCsrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $choices = [
            'pay' => 'successful',
            'fail' => 'failed',
            'cancel' => 'cancelled',
        ];

        $newStatus = $choices[$_POST['choice'] ?? ''] ?? null;

        if ($newStatus !== null) {
            $paymentId = $gateway->finishPayment($txRef, $newStatus);

            if ($paymentId !== null) {
                header('Location: ' . $callbackUrl . '?' . http_build_query([
                    'status' => $newStatus,
                    'tx_ref' => $txRef,
                    'transaction_id' => $paymentId,
                ]));
                exit;
            }
        }
    }

    $payment = $gateway->getPaymentByRef($txRef);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sandbox Checkout</title>
</head>
<body>

    <h1>Sandbox Checkout</h1>
    <p><strong>This is a simulation. No real money moves.</strong></p>

    <?php if ($error): ?>
        <p><strong>Error:</strong> <?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if (!$payment): ?>

        <p>Payment not found.</p>

    <?php else: ?>

        <p><?= htmlspecialchars((string) $payment['description']) ?></p>
        <p>Amount:
            <strong><?= number_format($payment['amount'], 2) ?> <?= htmlspecialchars($payment['currency']) ?></strong></p>
        <p>Status: <strong><?= htmlspecialchars(strtoupper($payment['status'])) ?></strong></p>

        <?php if ($payment['status'] === 'initiated'): ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="ref" value="<?= htmlspecialchars($payment['tx_ref']) ?>">

                <button type="submit" name="choice" value="pay">Pay successfully</button>
                <button type="submit" name="choice" value="fail">Simulate a failed payment</button>
                <button type="submit" name="choice" value="cancel">Cancel</button>
            </form>

        <?php else: ?>

            <p>This payment is already finished.</p>

        <?php endif; ?>

    <?php endif; ?>

</body>
</html>
