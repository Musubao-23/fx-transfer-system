<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

use App\Mail\Mailer;
use App\User\LoginThrottle;

requireLogin();

$userId = (int) $_SESSION['user_id'];
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

$stmt = $pdo->prepare(
    "SELECT full_name, email, phone, password_hash FROM users WHERE id = ? LIMIT 1"
);
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: logout.php');
    exit;
}

$errors = [];
$messages = [];
$formName = $user['full_name'];
$formPhone = (string) $user['phone'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if (!checkCsrf()) {

        $errors[] = 'Your session expired. Please try again.';

    } elseif ($action === 'profile') {

        $formName = trim($_POST['full_name'] ?? '');
        $formPhone = str_replace(' ', '', trim($_POST['phone'] ?? ''));

        if (mb_strlen($formName) < 2 || mb_strlen($formName) > 150) {
            $errors[] = 'Please enter your full name.';
        }
        if (!preg_match('/^\+?[0-9]{9,15}$/', $formPhone)) {
            $errors[] = 'Please enter a valid phone number (9 to 15 digits, with an optional + at the start).';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET full_name = ?, phone = ? WHERE id = ?");
                $stmt->execute([$formName, $formPhone, $userId]);

                $_SESSION['full_name'] = $formName;
                $user['full_name'] = $formName;
                $user['phone'] = $formPhone;
                $messages[] = 'Your details were updated.';
            } catch (\PDOException $e) {
                error_log('Profile update failed: ' . $e->getMessage());
                $errors[] = 'We could not save your details right now. Please try again.';
            }
        }

    } elseif ($action === 'password') {

        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        try {
            $throttle = new LoginThrottle($pdo);

            if ($throttle->isLocked($user['email'], $ip)) {
                $errors[] = 'Too many failed attempts. Please try again in 15 minutes.';
            } elseif (!password_verify($current, $user['password_hash'])) {
                $throttle->recordFailure($user['email'], $ip);
                $errors[] = 'Your current password is incorrect.';
            } elseif (strlen($new) < 8) {
                $errors[] = 'Your new password must be at least 8 characters.';
            } elseif ($new !== $confirm) {
                $errors[] = 'The two new passwords do not match.';
            } elseif ($new === $current) {
                $errors[] = 'Your new password must be different from the current one.';
            } else {
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);

                $throttle->clear($user['email']);
                session_regenerate_id(true);
                $messages[] = 'Your password was changed.';

                // Tell the owner by email; a mail problem must not undo the change
                $note = 'Your password was just changed. If this was not you, reset your password again right away.';
                $mailer = new Mailer();
                $mailer->send(
                    $user['email'],
                    $user['full_name'],
                    'Your password was changed',
                    '<p>' . $note . '</p>',
                    $note
                );
            }
        } catch (\PDOException $e) {
            error_log('Password change failed: ' . $e->getMessage());
            $errors[] = 'We could not change your password right now. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account</title>
</head>
<body>

    <?php require __DIR__ . '/_banner.php'; ?>

    <h1>My Account</h1>

    <p>
        Signed in as <?= htmlspecialchars($user['full_name']) ?>
        | <a href="history.php">History</a>
        | <a href="transfer.php">New transfer</a>
        | <a href="logout.php">Log out</a>
    </p>

    <?php foreach ($messages as $message): ?>
        <p><?= htmlspecialchars($message) ?></p>
    <?php endforeach; ?>

    <?php foreach ($errors as $message): ?>
        <p><strong>Error:</strong> <?= htmlspecialchars($message) ?></p>
    <?php endforeach; ?>

    <h2>Your details</h2>

    <p>Email: <strong><?= htmlspecialchars($user['email']) ?></strong> (cannot be changed here)</p>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <input type="hidden" name="action" value="profile">

        <label for="full_name">Full name:</label>
        <input type="text" name="full_name" id="full_name" value="<?= htmlspecialchars($formName) ?>" required>
        <br><br>

        <label for="phone">Phone:</label>
        <input type="text" name="phone" id="phone" value="<?= htmlspecialchars($formPhone) ?>" required>
        <br><br>

        <button type="submit">Save details</button>
    </form>

    <h2>Change password</h2>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <input type="hidden" name="action" value="password">

        <label for="current_password">Current password:</label>
        <input type="password" name="current_password" id="current_password" required>
        <br><br>

        <label for="new_password">New password (at least 8 characters):</label>
        <input type="password" name="new_password" id="new_password" required>
        <br><br>

        <label for="confirm_password">Confirm new password:</label>
        <input type="password" name="confirm_password" id="confirm_password" required>
        <br><br>

        <button type="submit">Change password</button>
    </form>

</body>
</html>