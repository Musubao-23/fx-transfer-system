<?php

// Starts the session with safe settings and offers small helpers.

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

function requireLogin()
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function csrfToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function checkCsrf()
{
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}
function isAdmin($pdo)
{
    if (!isLoggedIn()) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && $row['role'] === 'admin';
}

// For pages inside public/admin/. The role is checked in the database every time,
// so a removed admin loses access immediately.
function requireAdmin($pdo)
{
    if (!isLoggedIn()) {
        header('Location: ../login.php');
        exit;
    }

    if (!isAdmin($pdo)) {
        http_response_code(403);
        exit('Access denied.');
    }
}