<?php

namespace ProofDesk\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): ?PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host = Env::get('DB_HOST');
        $port = Env::get('DB_PORT', '5432');
        $dbname = Env::get('DB_NAME', 'postgres');
        $user = Env::get('DB_USER');
        $password = Env::get('DB_PASSWORD');

        if (empty($host) || empty($user)) {
            return null;
        }

        try {
            $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s;sslmode=require", $host, $port, $dbname);
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            self::$instance = new PDO($dsn, $user, $password, $options);
            return self::$instance;
        } catch (PDOException $e) {
            error_log('ProofDesk PDO Connection Error: ' . $e->getMessage());
            return null;
        }
    }

    public static function isAvailable(): bool
    {
        return self::getConnection() !== null;
    }
}
