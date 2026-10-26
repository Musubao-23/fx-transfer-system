<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';

use App\Conversion\RateFetcher;
use App\Conversion\RateUpdater;

requireAdmin($pdo);

$messages = [];
$errors = [];

function refreshRates($pdo)
{
    $updater = new RateUpdater($pdo, new RateFetcher());
    return $updater->updateRates();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!checkCsrf()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'toggle') {

            $currencyId = (int) ($_POST['id'] ?? 0);

            $stmt = $pdo->prepare("SELECT code, is_active FROM currencies WHERE id = ?");
            $stmt->execute([$currencyId]);
            $currency = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$currency) {
                $errors[] = 'Currency not found.';
            } elseif ($currency['code'] === 'USD') {
                $errors[] = 'USD cannot be switched off, because every rate is calculated through it.';
            } else {
                $newValue = $currency['is_active'] ? 0 : 1;

                $stmt = $pdo->prepare("UPDATE currencies SET is_active = ? WHERE id = ?");
                $stmt->execute([$newValue, $currencyId]);

                if ($newValue === 1) {
                    // A currency that was off has old rates, so fetch fresh ones
                    try {
                        refreshRates($pdo);
                    } catch (\Exception $e) {
                        error_log('Rate refresh failed: ' . $e->getMessage());
                        $errors[] = 'Rates could not be refreshed. Use "Refresh rates now" later.';
                    }
                }

                $messages[] = $currency['code'] . ($newValue === 1 ? ' is now active.' : ' is now switched off.');
            }

        } elseif ($action === 'add') {

            $code = strtoupper(trim($_POST['code'] ?? ''));
            $name = trim($_POST['name'] ?? '');

            if (!preg_match('/^[A-Z]{3}$/', $code)) {
                $errors[] = 'The code must be exactly 3 letters, for example TZS.';
            }
            if (mb_strlen($name) < 2 || mb_strlen($name) > 50) {
                $errors[] = 'The name must be between 2 and 50 characters.';
            }

            if (empty($errors)) {
                $stmt = $pdo->prepare("SELECT id FROM currencies WHERE code = ?");
                $stmt->execute([$code]);

                if ($stmt->fetch()) {
                    $errors[] = $code . ' already exists.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO currencies (code, name, is_active) VALUES (?, ?, 1)");
                    $stmt->execute([$code, $name]);
                    $newId = (int) $pdo->lastInsertId();

                    try {
                        refreshRates($pdo);

                        $stmt = $pdo->prepare(
                            "SELECT COUNT(*) FROM exchange_rates WHERE target_currency_id = ?"
                        );
                        $stmt->execute([$newId]);

                        if ((int) $stmt->fetchColumn() === 0) {
                            $stmt = $pdo->prepare("UPDATE currencies SET is_active = 0 WHERE id = ?");
                            $stmt->execute([$newId]);
                            $errors[] = 'The rate service has no rate for ' . $code
                                      . ', so it was added but left switched off.';
                        } else {
                            $messages[] = $code . ' was added and its rate was loaded.';
                        }
                    } catch (\Exception $e) {
                        error_log('Rate refresh failed: ' . $e->getMessage());
                        $errors[] = $code . ' was added, but rates could not be loaded right now. '
                                  . 'Use "Refresh rates now" later.';
                    }
                }
            }

        } elseif ($action === 'refresh') {

            try {
                $saved = refreshRates($pdo);
                $messages[] = 'Rates refreshed: ' . $saved . ' rate(s) saved.';
            } catch (\Exception $e) {
                error_log('Rate refresh failed: ' . $e->getMessage());
                $errors[] = 'Rates could not be refreshed right now.';
            }
        }
    }
}

$currencies = $pdo->query(
    "SELECT c.id, c.code, c.name, c.is_active,
            (SELECT MAX(r.fetched_at) FROM exchange_rates r WHERE r.target_currency_id = c.id) AS last_rate_time
     FROM currencies c
     ORDER BY c.code"
)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Currencies</title>
</head>
<body>

    <h1>Currencies</h1>

    <?php require __DIR__ . '/_nav.php'; ?>

    <?php foreach ($messages as $message): ?>
        <p><?= htmlspecialchars($message) ?></p>
    <?php endforeach; ?>

    <?php foreach ($errors as $message): ?>
        <p><strong>Error:</strong> <?= htmlspecialchars($message) ?></p>
    <?php endforeach; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <input type="hidden" name="action" value="refresh">
        <button type="submit">Refresh rates now</button>
    </form>

    <h2>Add a currency</h2>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <input type="hidden" name="action" value="add">

        <label for="code">Code (3 letters):</label>
        <input type="text" name="code" id="code" maxlength="3" required>

        <label for="name">Name:</label>
        <input type="text" name="name" id="name" maxlength="50" required>

        <button type="submit">Add currency</button>
    </form>

    <h2>All currencies (<?= count($currencies) ?>)</h2>

    <table border="1" cellpadding="6" cellspacing="0">
        <tr><th>Code</th><th>Name</th><th>Status</th><th>Last rate loaded</th><th>Action</th></tr>
        <?php foreach ($currencies as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['code']) ?></td>
                <td><?= htmlspecialchars($c['name']) ?></td>
                <td><?= $c['is_active'] ? 'Active' : 'Switched off' ?></td>
                <td><?= $c['code'] === 'USD' ? 'base currency' : htmlspecialchars((string) ($c['last_rate_time'] ?? 'never')) ?></td>
                <td>
                    <?php if ($c['code'] !== 'USD'): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button type="submit"><?= $c['is_active'] ? 'Switch off' : 'Switch on' ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

</body>
</html>