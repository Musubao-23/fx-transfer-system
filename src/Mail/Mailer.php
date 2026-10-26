<?php

namespace App\Mail;

require_once __DIR__ . '/../../config/env.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    public $lastError = '';

    public function send($toEmail, $toName, $subject, $htmlBody, $textBody)
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = env('MAIL_HOST');
            $mail->Port = (int) env('MAIL_PORT', 2525);
            $mail->SMTPAuth = true;
            $mail->Username = env('MAIL_USERNAME');
            $mail->Password = env('MAIL_PASSWORD');
            $mail->CharSet = 'UTF-8';

            $mail->setFrom(env('MAIL_FROM'), env('MAIL_FROM_NAME'));
            $mail->addAddress($toEmail, $toName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            $mail->send();
            return true;
        } catch (Exception $e) {
            $this->lastError = $mail->ErrorInfo;
            error_log('Mail failed: ' . $mail->ErrorInfo);
            return false;
        }
    }
}