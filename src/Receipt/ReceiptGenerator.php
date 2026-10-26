<?php

namespace App\Receipt;

use Dompdf\Dompdf;
use Dompdf\Options;

class ReceiptGenerator
{
    private $pdo;
    private $receiptsDir;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->receiptsDir = __DIR__ . '/../../public/receipts/';

        if (!is_dir($this->receiptsDir)) {
            mkdir($this->receiptsDir, 0777, true);
        }
    }

    public function generateReceipt($transactionId)
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                id, sender_name, sender_phone, sender_country,
                receiver_name, receiver_phone, receiver_country,
                amount_sent, source_currency_id, target_currency_id,
                rate_applied, amount_received, status,
                purpose_of_transfer, created_at
             FROM transactions
             WHERE id = ?"
        );
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch();

        if (!$transaction) {
            return false;
        }

        $receiptNumber = 'CTR-' . date('YmdHis') . '-' . $transactionId;
        $fileName = $receiptNumber . '.pdf';
        $filePath = 'receipts/' . $fileName;

        $html = $this->buildHtml($transaction, $receiptNumber);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

$pdfContent = $dompdf->output();
$bytesWritten = file_put_contents(
    $this->receiptsDir . $fileName,
    $pdfContent
);

if ($bytesWritten === false) {
    throw new \RuntimeException(
        'Could not write PDF to: ' . $this->receiptsDir . $fileName
    );
}

if ($bytesWritten === 0) {
    throw new \RuntimeException('The PDF file was empty.');
}
        $stmt = $this->pdo->prepare(
            "INSERT INTO receipts (transaction_id, receipt_number, file_path)
             VALUES (?, ?, ?)"
        );
        $stmt->execute([$transactionId, $receiptNumber, $filePath]);

        return [
            'receipt_number' => $receiptNumber,
            'file_path' => $filePath,
            'transaction' => $transaction
        ];
    }

    private function buildHtml($t, $receiptNumber)
    {
        return "
        <html>
        <head>
        <style>
            body { font-family: sans-serif; font-size: 14px; color: #222; }
            h1 { text-align: center; color: rgb(170, 197, 107); font-size: 20px; }
            .section { margin-top: 20px; }
            .section-title { background: #0bd759; color: #fff; padding: 5px 10px; font-weight: bold; }
            table { width: 100%; border-collapse: collapse; margin-top: 5px; }
            td { padding: 6px 10px; border-bottom: 1px solid #ddd; }
            .label { font-weight: bold; width: 40%; }
            .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #666; }
        </style>
        </head>
        <body>
            <h1>CURRENCY TRANSFER RECEIPT</h1>
            <p><strong>Receipt Number:</strong> {$receiptNumber}<br>
               <strong>Date:</strong> {$t['created_at']}<br>
               <strong>Status:</strong> " . strtoupper($t['status']) . "</p>

            <div class='section'>
                <div class='section-title'>Sender</div>
                <table>
                    <tr><td class='label'>Name</td><td>{$t['sender_name']}</td></tr>
                    <tr><td class='label'>Phone</td><td>{$t['sender_phone']}</td></tr>
                    <tr><td class='label'>Country</td><td>{$t['sender_country']}</td></tr>
                </table>
            </div>

            <div class='section'>
                <div class='section-title'>Receiver</div>
                <table>
                    <tr><td class='label'>Name</td><td>{$t['receiver_name']}</td></tr>
                    <tr><td class='label'>Phone</td><td>{$t['receiver_phone']}</td></tr>
                    <tr><td class='label'>Country</td><td>{$t['receiver_country']}</td></tr>
                </table>
            </div>

            <div class='section'>
                <div class='section-title'>Transaction Details</div>
                <table>
                    <tr><td class='label'>Amount Sent</td><td>" . number_format($t['amount_sent'], 2) . "</td></tr>
                    <tr><td class='label'>Exchange Rate Applied</td><td>{$t['rate_applied']}</td></tr>
                    <tr><td class='label'>Amount Received</td><td>" . number_format($t['amount_received'], 2) . "</td></tr>
                    <tr><td class='label'>Purpose</td><td>{$t['purpose_of_transfer']}</td></tr>
                </table>
            </div>

            <div class='footer'>Thank you for using our service.</div>
        </body>
        </html>
        ";
    }
}