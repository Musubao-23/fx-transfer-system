<?php

namespace App\User;

class LoginThrottle
{
    private $pdo;
    private $windowMinutes = 15;
    private $maxPerEmail = 5;
    private $maxPerIp = 20;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function isLocked($email, $ip)
    {
        $stmt = $this->pdo->prepare(
            "SELECT SUM(email = ?) AS by_email, SUM(ip = ?) AS by_ip
             FROM login_attempts
             WHERE attempted_at > DATE_SUB(NOW(), INTERVAL " . (int) $this->windowMinutes . " MINUTE)"
        );
        $stmt->execute([substr($email, 0, 150), substr($ip, 0, 45)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return (int) $row['by_email'] >= $this->maxPerEmail
            || (int) $row['by_ip'] >= $this->maxPerIp;
    }

    public function recordFailure($email, $ip)
    {
        $stmt = $this->pdo->prepare("INSERT INTO login_attempts (email, ip) VALUES (?, ?)");
        $stmt->execute([substr($email, 0, 150), substr($ip, 0, 45)]);

        // Old rows are no longer needed
        $this->pdo->exec(
            "DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)"
        );
    }

    public function clear($email)
    {
        $stmt = $this->pdo->prepare("DELETE FROM login_attempts WHERE email = ?");
        $stmt->execute([substr($email, 0, 150)]);
    }
}