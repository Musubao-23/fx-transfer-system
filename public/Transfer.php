<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

use App\Conversion\CurrencyConverter;
use App\Conversion\RateFetcher;
use App\Conversion\RateUpdater;
use App\Transfer\TransactionLogger;
use App\Transfer\TransferService;

requireLogin();

$userId = (int) $_SESSION['user_id'];

$purposes = [
    'Family support', 'School fees', 'Business payment',
    'Medical bills', 'Rent or bills', 'Gift', 'Other',
];

$currencies = $pdo->query(
    "SELECT id, code, name FROM currencies WHERE is_active = 1 ORDER BY code"
)->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT full_name, phone FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

$service = new TransferService(
    $pdo,
    new CurrencyConverter($pdo),
    new TransactionLogger($pdo)
);

$errors = [];
$notice = null;
$created = null;

$form = [
    'amount' => '',
    'source_currency_id' => 0,
    'target_currency_id' => 0,
    'sender_country' => 'Uganda',
    'receiver_name' => '',
    'receiver_phone' => '',
    'receiver_country' => '',
    'purpose' => '',
];

$quote = $_SESSION['transfer_quote'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!checkCsrf()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'cancel') {

            unset($_SESSION['transfer_quote']);
            $quote = null;

        } elseif ($action === 'quote') {

            unset($_SESSION['transfer_quote']);
            $quote = null;

            $form['amount'] = trim($_POST['amount'] ?? '');
            $form['source_currency_id'] = (int) ($_POST['source_currency_id'] ?? 0);
            $form['target_currency_id'] = (int) ($_POST['target_currency_id'] ?? 0);
            $form['sender_country'] = trim($_POST['sender_country'] ?? '');
            $form['receiver_name'] = trim($_POST['receiver_name'] ?? '');
            $form['receiver_phone'] = str_replace(' ', '', trim($_POST['receiver_phone'] ?? ''));
            $form['receiver_country'] = trim($_POST['receiver_country'] ?? '');
            $form['purpose'] = trim($_POST['purpose'] ?? '');

            $amount = filter_var($form['amount'], FILTER_VALIDATE_FLOAT);

            if ($amount === false || $amount <= 0) {
                $errors[] = 'Please enter a valid amount greater than zero.';
            }
            if ($form['source_currency_id'] === 0 || $form['target_currency_id'] === 0) {
                $errors[] = 'Please choose both currencies.';
            }
            if (mb_strlen($form['sender_country']) < 2 || mb_strlen($form['sender_country']) > 100) {
                $errors[] = 'Please enter your country.';
            }
            if (mb_strlen($form['receiver_name']) < 2 || mb_strlen($form['receiver_name']) > 150) {
                $errors[] = 'Please enter the receiver\'s full name.';
            }
            if (!preg_match('/^\+?[0-9]{9,15}$/', $form['receiver_phone'])) {
                $errors[] = 'Please enter a valid receiver phone number (9 to 15 digits, with an optional + at the start).';
            }
            if (mb_strlen($form['receiver_country']) < 2 || mb_strlen($form['receiver_country']) > 100) {
                $errors[] = 'Please enter the receiver\'s country.';
            }
            if (!in_array($form['purpose'], $purposes, true)) {
                $errors[] = 'Please choose the purpose of the transfer.';
            }

            if (empty($errors)) {
                // Refresh saved rates if they are older than 60 minutes
                try {
                    $updater = new RateUpdater($pdo, new RateFetcher());
                    $updater->updateIfStale(60);
                } catch (\Exception $e) {
                    error_log('Rate refresh failed: ' . $e->getMessage());
                }

                try {
                    $result = $service->createQuote(
                        (float) $amount,
                        $form['source_currency_id'],
                        $form['target_currency_id']
                    );

                    $result['expires_at'] = time() + TransferService::LOCK_SECONDS;
                    $result['details'] = [
                        'sender_name' => $account['full_name'],
                        'sender_phone' => $account['phone'],
                        'sender_country' => $form['sender_country'],
                        'receiver_name' => $form['receiver_name'],
                        'receiver_phone' => $form['receiver_phone'],
                        'receiver_country' => $form['receiver_country'],
                        'purpose' => $form['purpose'],
                    ];

                    $_SESSION['transfer_quote'] = $result;
                    $quote = $result;

                } catch (\InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();
                } catch (\Exception $e) {
                    error_log('Quote failed: ' . $e->getMessage());
                    $errors[] = 'The exchange rates could not be loaded right now. Please try again later.';
                }
            }

        } elseif ($action === 'confirm') {

            $quote = $_SESSION['transfer_quote'] ?? null;
            $secondsLeft = $quote ? $quote['expires_at'] - time() : 0;

            if ($quote === null) {
                $errors[] = 'There is no transfer to confirm. Please start again.';
            } elseif ($secondsLeft <= 0) {
                unset($_SESSION['transfer_quote']);
                $quote = null;
                $errors[] = 'Your quote expired. Please get a new one.';
            } else {
                try {
                    $transactionId = $service->createTransfer(
                        $userId,
                        $quote,
                        $quote['details'],
                        $secondsLeft
                    );

                    unset($_SESSION['transfer_quote']);
                    header('Location: transfer.php?created=' . $transactionId);
                    exit;
                } catch (\Exception $e) {
                    error_log('Transfer failed: ' . $e->getMessage());
                    $errors[] = 'We could not save your transfer right now. Please try again.';
                }
            }
        }
    }
}

// A quote that sat too long is dropped
if ($quote !== null && $quote['expires_at'] <= time()) {
    unset($_SESSION['transfer_quote']);
    $quote = null;
    $notice = 'Your quote expired. Please get a new one.';
}

// Page shown after a transfer was saved
if (isset($_GET['created'])) {
    $stmt = $pdo->prepare("SELECT id, status FROM transactions WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([(int) $_GET['created'], $userId]);
    $created = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Money</title>
</head>
<body>

    <?php require __DIR__ . '/_banner.php'; ?>

    <h1>Send Money</h1>

    <p>
        Signed in as <?= htmlspecialchars($account['full_name']) ?>
        | <a href="history.php">History</a>
        | <a href="logout.php">Log out</a>
    </p>

    <?php if ($notice): ?>
        <p><?= htmlspecialchars($notice) ?></p>
    <?php endif; ?>

    <?php foreach ($errors as $message): ?>
        <p><strong>Error:</strong> <?= htmlspecialchars($message) ?></p>
    <?php endforeach; ?>

    <?php if ($created): ?>

        <h2>Transfer created</h2>
        <p>
            Transfer #<?= (int) $created['id'] ?> was saved with status
            <strong><?= htmlspecialchars(strtoupper($created['status'])) ?></strong>.
        </p>
               <?php if ($created['status'] === 'pending'): ?>
            <p><a href="pay.php?id=<?= (int) $created['id'] ?>">Pay now</a> to complete this transfer.</p>
        <?php endif; ?>
        <p><a href="transfer.php">New transfer</a> | <a href="history.php">View history</a></p>

    <?php elseif ($quote !== null): ?>

        <?php
            $left = max(0, $quote['expires_at'] - time());
            $d = $quote['details'];
            $rateText = rtrim(rtrim(number_format($quote['rate'], 10, '.', ''), '0'), '.');
        ?>

        <h2>Review your transfer</h2>

        <p>You send:
            <strong><?= number_format($quote['amount_sent'], 2) ?> <?= htmlspecialchars($quote['source_code']) ?></strong>
        </p>
        <p>Receiver gets:
            <strong><?= number_format($quote['amount_received'], 2) ?> <?= htmlspecialchars($quote['target_code']) ?></strong>
        </p>
        <p>Exchange rate: <strong><?= htmlspecialchars($rateText) ?></strong></p>
        <p>This rate is locked for about <?= (int) ceil($left / 60) ?> more minute(s).</p>

        <p>Receiver: <?= htmlspecialchars($d['receiver_name']) ?>,
           <?= htmlspecialchars($d['receiver_phone']) ?>,
           <?= htmlspecialchars($d['receiver_country']) ?></p>
        <p>Purpose: <?= htmlspecialchars($d['purpose']) ?></p>

        <form method="POST" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="confirm">
            <button type="submit">Confirm transfer</button>
        </form>

        <form method="POST" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="cancel">
            <button type="submit">Cancel</button>
        </form>

    <?php else: ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="action" value="quote">

            <label for="amount">Amount to send:</label>
            <input type="number" name="amount" id="amount" step="0.01" min="0"
                   value="<?= htmlspecialchars($form['amount']) ?>" required>
            <br><br>

            <label for="source_currency_id">From currency:</label>
            <select name="source_currency_id" id="source_currency_id" required>
                <option value="">Select currency</option>
                <?php foreach ($currencies as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"
                        <?= (int) $c['id'] === $form['source_currency_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['code']) ?> - <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <br><br>

            <label for="target_currency_id">To currency:</label>
            <select name="target_currency_id" id="target_currency_id" required>
                <option value="">Select currency</option>
                <?php foreach ($currencies as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"
                        <?= (int) $c['id'] === $form['target_currency_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['code']) ?> - <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <br><br>

            <label for="sender_country">Your country:</label>
            <input type="text" name="sender_country" id="sender_country"
                   value="<?= htmlspecialchars($form['sender_country']) ?>" required>
            <br><br>

            <label for="receiver_name">Receiver's full name:</label>
            <input type="text" name="receiver_name" id="receiver_name"
                   value="<?= htmlspecialchars($form['receiver_name']) ?>" required>
            <br><br>

            <label for="receiver_phone">Receiver's phone:</label>
            <input type="text" name="receiver_phone" id="receiver_phone"
                   value="<?= htmlspecialchars($form['receiver_phone']) ?>" required>
            <br><br>

            <label for="receiver_country">Receiver's country:</label>
            <input type="text" name="receiver_country" id="receiver_country"
                   value="<?= htmlspecialchars($form['receiver_country']) ?>" required>
            <br><br>

            <label for="purpose">Purpose of transfer:</label>
            <select name="purpose" id="purpose" required>
                <option value="">Select purpose</option>
                <?php foreach ($purposes as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>"
                        <?= $p === $form['purpose'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <br><br>
            <button type="submit">Get quote</button>
        </form>

    <?php endif; ?>

</body>
</html>