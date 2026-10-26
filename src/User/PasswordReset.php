<?php

namespace App\User;

class PasswordReset
{
    private $pdo;
    private $codeMinutes = 10;
    private $maxAttempts = 5;
    private $maxRequestsPerHour = 3;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    // Creates a 6-digit code for this email.
    // Returns ['code', 'full_name', 'email'], or null if the email is unknown
    // or too many codes were requested in the last hour.
    public function requestCode($email)
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, full_name, email FROM users WHERE email = ? LIMIT 1"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total FROM password_resets
             WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        if ((int) $row['total'] >= $this->maxRequestsPerHour) {
            return null;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->pdo->beginTransaction();

        try {
            // Only the newest code stays valid
            $stmt = $this->pdo->prepare(
                "UPDATE password_resets SET used_at = NOW()
                 WHERE user_id = ? AND used_at IS NULL"
            );
            $stmt->execute([$user['id']]);

            $stmt = $this->pdo->prepare(
                "INSERT INTO password_resets (user_id, code_hash, expires_at)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . (int) $this->codeMinutes . " MINUTE))"
            );
            $stmt->execute([$user['id'], password_hash($code, PASSWORD_DEFAULT)]);

            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'code' => $code,
            'full_name' => $user['full_name'],
            'email' => $user['email'],
        ];
    }

    // Returns ['id', 'full_name', 'email'] on success, or false for any problem
    public function resetPassword($email, $code, $newPassword)
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.full_name, u.email, r.id AS reset_id, r.code_hash
             FROM password_resets r
             JOIN users u ON u.id = r.user_id
             WHERE u.email = ? AND r.used_at IS NULL AND r.expires_at > NOW()
             ORDER BY r.id DESC
             LIMIT 1"
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        // Count this attempt first, so parallel guesses cannot beat the limit
        $stmt = $this->pdo->prepare(
            "UPDATE password_resets SET attempts = attempts + 1
             WHERE id = ? AND attempts < " . (int) $this->maxAttempts
        );
        $stmt->execute([$row['reset_id']]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        if (!password_verify($code, $row['code_hash'])) {
            return false;
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $row['id']]);

            $stmt = $this->pdo->prepare(
                "UPDATE password_resets SET used_at = NOW()
                 WHERE user_id = ? AND used_at IS NULL"
            );
            $stmt->execute([$row['id']]);

            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'id' => (int) $row['id'],
            'full_name' => $row['full_name'],
            'email' => $row['email'],
        ];
    }
}