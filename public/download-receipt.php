<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

requireLogin();

$userId = (int) $_SESSION['user_id'];
$transactionId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT r.file_path, r.receipt_number
     FROM receipts r
     JOIN transactions t ON t.id = r.transaction_id
     WHERE r.transaction_id = ? AND t.user_id = ? AND t.status = 'completed'
     LIMIT 1"
);
$stmt->execute([$transactionId, $userId]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

$base = realpath(__DIR__ . '/receipts');
$file = $receipt ? realpath(__DIR__ . '/' . $receipt['file_path']) : false;

// Only files inside the receipts folder can ever be sent
if (!$receipt || !$base || !$file
    || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0
    || !is_file($file)) {
    http_response_code(404);
    exit('Receipt not found.');
}

$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$type = $extension === 'pdf' ? 'application/pdf' : 'text/plain';
$name = preg_replace('/[^A-Za-z0-9._-]/', '_', $receipt['receipt_number']) . '.' . $extension;

header('Content-Type: ' . $type);
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($file));
readfile($file);
exit;