<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

use App\Mail\Mailer;
use App\User\PasswordReset;

$errors = [];
$done = false;
$email = $_SESSION['reset_email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!checkCsrf()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $code = trim($_POST['code'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!preg_match('/^[0-9]{6}$/', $code)) {
            $errors[] = 'Enter the 6-digit code from your email.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Your new password must be at least 8 characters.';
        }
        if ($password !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        }

        if (empty($errors)) {
            try {
                $reset = new PasswordReset($pdo);
                $user = $reset->resetPassword($email, $code, $password);

                if ($user === false) {
                    $errors[] = 'The code is incorrect or has expired. Check the code, or request a new one.';
                } else {
                    unset($_SESSION['reset_email']);
                    $done = true;

                    // Tell the owner that the password was changed
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
                error_log('Password reset failed: ' . $e->getMessage());
                $errors[] = 'We could not change your password right now. Please try again.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
</head>
<body>

    <?php require __DIR__ . '/_banner.php'; ?>

    <h1>Reset Password</h1>

    <?php if ($done): ?>

        <p>Your password has been changed. <a href="login.php">Log in</a></p>

    <?php else: ?>

        <p>Enter the 6-digit code we emailed you, then choose a new password.</p>

        <?php foreach ($errors as $message): ?>
            <p><strong>Error:</strong> <?= htmlspecialchars($message) ?></p>
        <?php endforeach; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

            <label for="email">Email:</label>
            <input type="email" name="email" id="email" value="<?= htmlspecialchars($email) ?>" required>
            <br><br>

            <label for="code">6-digit code:</label>
            <input type="text" name="code" id="code" inputmode="numeric" maxlength="6" required>
            <br><br>

            <label for="password">New password (at least 8 characters):</label>
            <input type="password" name="password" id="password" required>
            <br><br>

            <label for="confirm_password">Confirm new password:</label>
            <input type="password" name="confirm_password" id="confirm_password" required>
            <br><br>

            <button type="submit">Change password</button>
        </form>

        <p><a href="forgot-password.php">Request a new code</a></p>

    <?php endif; ?>

</body>
</html>