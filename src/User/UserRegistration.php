<?php

namespace App\User;

class UserRegistration
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function register(
        $fullName,
        $email,
        $phone,
        $password
    ) {
        // Check whether the email already exists
        $stmt = $this->pdo->prepare(
            "SELECT id FROM users WHERE email = ? LIMIT 1"
        );

        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            return false;
        }

        // Hash the password before storing it
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // Create the user
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (
                full_name,
                email,
                phone,
                password_hash,
                role
            ) VALUES (?, ?, ?, ?, 'user')"
        );

        $stmt->execute([
            $fullName,
            $email,
            $phone,
            $passwordHash
        ]);

        return $this->pdo->lastInsertId();
    }
}