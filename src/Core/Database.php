<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private ?PDO $pdo = null;

    public function __construct()
    {
        $config = require __DIR__ . '/../../config/database.php';

        $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";

        try {
            $this->pdo = new PDO($dsn, $config['user'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // In a real app we might want to log this but for now checking connection
            if (str_contains($e->getMessage(), "Unknown database")) {
                // Attempt to create only if strictly necessary or instruct user
                throw new \Exception("Database '{$config['dbname']}' does not exist. Please run the SQL setup script.");
            }
            throw $e;
        }
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }
}
