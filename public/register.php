<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

use App\User\UserRegistration;

$errors = [];
$fullName = '';
$email = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!checkCsrf()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = str_replace(' ', '', trim($_POST['phone'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 150) {
            $errors[] = 'Please enter your full name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (!preg_match('/^\+?[0-9]{9,15}$/', $phone)) {
            $errors[] = 'Please enter a valid phone number (digits only, with an optional + at the start).';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Your password must be at least 8 characters.';
        }
        if ($password !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        }

        if (empty($errors)) {
            try {
                $registration = new UserRegistration($pdo);
                $userId = $registration->register($fullName, $email, $phone, $password);

                if ($userId === false) {
                    $errors[] = 'An account with this email already exists.';
                } else {
                    header('Location: login.php?registered=1');
                    exit;
                }
            } catch (\PDOException $e) {
                error_log('Registration failed: ' . $e->getMessage());
                $errors[] = 'We could not create your account right now. Please try again.';
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
    <title>Create Account</title>
</head>
<body>

    <?php require __DIR__ . '/_banner.php'; ?>

    <h1>Create Account</h1>

    <?php foreach ($errors as $message): ?>
        <p><strong>Error:</strong> <?= htmlspecialchars($message) ?></p>
    <?php endforeach; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

        <label for="full_name">Full name:</label>
        <input type="text" name="full_name" id="full_name" value="<?= htmlspecialchars($fullName) ?>" required>
        <br><br>

        <label for="email">Email:</label>
        <input type="email" name="email" id="email" value="<?= htmlspecialchars($email) ?>" required>
        <br><br>

        <label for="phone">Phone:</label>
        <input type="text" name="phone" id="phone" value="<?= htmlspecialchars($phone) ?>" required>
        <br><br>

        <label for="password">Password (at least 8 characters):</label>
        <input type="password" name="password" id="password" required>
        <br><br>

        <label for="confirm_password">Confirm password:</label>
        <input type="password" name="confirm_password" id="confirm_password" required>
        <br><br>

        <button type="submit">Create account</button>
    </form>

    <p>Already have an account? <a href="login.php">Log in</a></p>

</body>
</html>