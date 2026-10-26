<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use App\Conversion\CurrencyConverter;
use App\Conversion\RateFetcher;
use App\Conversion\RateUpdater;

$converter = new CurrencyConverter($pdo);

$stmt = $pdo->query(
    "SELECT id, code, name
     FROM currencies
     WHERE is_active = 1
     ORDER BY code"
);

$currencies = $stmt->fetchAll(PDO::FETCH_ASSOC);

$result = null;
$rate = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $amount = (float) $_POST['amount'];
    $baseCurrencyId = (int) $_POST['base_currency_id'];
    $targetCurrencyId = (int) $_POST['target_currency_id'];

    if ($amount <= 0) {
        $error = "Please enter an amount greater than zero.";
    } elseif ($baseCurrencyId === $targetCurrencyId) {
        $error = "Please select two different currencies.";
    } else {

        // Refresh saved rates if they are older than 60 minutes
        try {
            $updater = new RateUpdater($pdo, new RateFetcher());
            $updater->updateIfStale(60);
        } catch (\Exception $e) {
            // Keep going: getRate() below refuses rates that are too old
            error_log('Rate refresh failed: ' . $e->getMessage());
        }

        try {
            $rate = $converter->getRate($baseCurrencyId, $targetCurrencyId);

            if ($rate === null) {
                $error = "No exchange rate is available for this currency pair.";
            } else {
                $result = $converter->convert($amount, $rate);
            }
        } catch (\Exception $e) {
            error_log('Conversion failed: ' . $e->getMessage());
            $error = "The exchange rates could not be loaded right now. Please try again later.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Currency Converter</title>
</head>

<body>

    <?php require __DIR__ . '/_banner.php'; ?>

    <h1>Currency Converter</h1>

    <form method="POST">

        <label for="amount">Amount:</label>
        <input
            type="number"
            name="amount"
            id="amount"
            step="0.01"
            min="0"
            required
        >

        <br><br>

        <label for="base_currency_id">From:</label>
        <select name="base_currency_id" id="base_currency_id" required>

            <option value="">Select currency</option>

            <?php foreach ($currencies as $currency): ?>

                <option value="<?= $currency['id'] ?>">
                    <?= htmlspecialchars($currency['code']) ?>
                    - <?= htmlspecialchars($currency['name']) ?>
                </option>

            <?php endforeach; ?>

        </select>

        <br><br>

        <label for="target_currency_id">To:</label>
        <select name="target_currency_id" id="target_currency_id" required>

            <option value="">Select currency</option>

            <?php foreach ($currencies as $currency): ?>

                <option value="<?= $currency['id'] ?>">
                    <?= htmlspecialchars($currency['code']) ?>
                    - <?= htmlspecialchars($currency['name']) ?>
                </option>

            <?php endforeach; ?>

        </select>

        <br><br>

        <button type="submit">Convert</button>

    </form>

    <?php if ($error): ?>

        <p>
            <strong>Error:</strong>
            <?= htmlspecialchars($error) ?>
        </p>

    <?php endif; ?>

    <?php if ($result !== null): ?>

        <h2>Conversion Result</h2>

        <p>
            Converted amount:
            <strong><?= number_format($result, 2) ?></strong>
        </p>

        <p>
            Exchange rate used:
            <strong><?= rtrim(rtrim(number_format($rate, 10, '.', ''), '0'), '.') ?></strong>
        </p>

    <?php endif; ?>

</body>
</html>