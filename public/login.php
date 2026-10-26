<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

use App\User\LoginThrottle;
use App\User\UserLogin;

if (isLoggedIn()) {
    header('Location: history.php');
    exit;
}

$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!checkCsrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        try {
            $throttle = new LoginThrottle($pdo);

            if ($throttle->isLocked($email, $ip)) {
                $error = 'Too many failed attempts. Please try again in 15 minutes.';
            } else {
                $login = new UserLogin($pdo);
                $user = $login->login($email, $password);

                if ($user === false) {
                    $throttle->recordFailure($email, $ip);
                    $error = 'Incorrect email or password.';
                } else {
                    $throttle->clear($email);

                    // A new session ID after login prevents session fixation
                    session_regenerate_id(true);
                    unset($_SESSION['csrf_token']);

                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];

                    header('Location: history.php');
                    exit;
                }
            }
        } catch (\PDOException $e) {
            error_log('Login failed: ' . $e->getMessage());
            $error = 'We could not sign you in right now. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In</title>
</head>
<body>

    <?php require __DIR__ . '/_banner.php'; ?>

    <h1>Log In</h1>

    <?php if (isset($_GET['registered'])): ?>
        <p>Your account was created. You can now log in.</p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p><strong>Error:</strong> <?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

        <label for="email">Email:</label>
        <input type="email" name="email" id="email" value="<?= htmlspecialchars($email) ?>" required>
        <br><br>

        <label for="password">Password:</label>
        <input type="password" name="password" id="password" required>
        <br><br>

        <button type="submit">Log in</button>
    </form>

    <p>No account yet? <a href="register.php">Create one</a></p>
    <p><a href="forgot-password.php">Forgot your password?</a></p>
    <p><a href="convert.php">Just converting? No account needed.</a></p>

</body>
</html>