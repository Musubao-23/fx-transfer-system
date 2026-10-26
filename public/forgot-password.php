<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

use App\Mail\Mailer;
use App\User\PasswordReset;

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!checkCsrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));

        try {
            $reset = new PasswordReset($pdo);
            $result = $reset->requestCode($email);

            if ($result !== null) {
                $name = htmlspecialchars($result['full_name']);
                $code = $result['code'];

                $html = '<p>Hello ' . $name . ',</p>'
                      . '<p>Your password reset code is:</p>'
                      . '<p style="font-size:24px;letter-spacing:4px;"><strong>' . $code . '</strong></p>'
                      . '<p>It expires in 10 minutes and can be used once. '
                      . 'If you did not ask for this, you can ignore this email.</p>';

                $text = "Hello " . $result['full_name'] . ",\n\n"
                      . "Your password reset code is: " . $code . "\n\n"
                      . "It expires in 10 minutes and can be used once. "
                      . "If you did not ask for this, you can ignore this email.";

                $mailer = new Mailer();
                $mailer->send(
                    $result['email'],
                    $result['full_name'],
                    'Your password reset code',
                    $html,
                    $text
                );
            }

            // The same answer whether or not the email exists
            $_SESSION['reset_email'] = $email;
            header('Location: reset-password.php');
            exit;
        } catch (\PDOException $e) {
            error_log('Reset request failed: ' . $e->getMessage());
            $error = 'We could not process your request right now. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
</head>
<body>

    <?php require __DIR__ . '/_banner.php'; ?>

    <h1>Forgot Password</h1>

    <?php if ($error): ?>
        <p><strong>Error:</strong> <?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <p>Enter your account email. If an account exists, we will send you a 6-digit code.</p>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

        <label for="email">Email:</label>
        <input type="email" name="email" id="email" required>
        <br><br>

        <button type="submit">Send code</button>
    </form>

    <p><a href="login.php">Back to log in</a></p>

</body>
</html>