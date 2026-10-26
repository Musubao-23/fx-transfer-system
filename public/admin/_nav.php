<?php

// Shared menu for the admin pages. It cannot be opened on its own.
if (!function_exists('requireAdmin')) {
    exit;
}

require __DIR__ . '/../_banner.php';
?>
<p>
    Admin: <?= htmlspecialchars($_SESSION['full_name']) ?>
    | <a href="index.php">Dashboard</a>
    | <a href="transactions.php">Transfers</a>
    | <a href="currencies.php">Currencies</a>
    | <a href="../history.php">My history</a>
    | <a href="../logout.php">Log out</a>
</p>
<hr>