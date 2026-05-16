<?php

declare(strict_types=1);

/**
 * Database — singleton PDO wrapper.
 *
 * Loads configuration from a .env file located at the project root.
 * Falls back to environment variables, then safe local defaults.
 */
class Database
{
    private static ?self $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        self::loadEnv();

        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $db   = $_ENV['DB_NAME'] ?? 'jdb_parts';
        $user = $_ENV['DB_USER'] ?? 'root';
        $pass = $_ENV['DB_PASS'] ?? '';
        $port = (int)($_ENV['DB_PORT'] ?? 3306);

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host, $port, $db
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            error_log('[DB] Connection failed: ' . $e->getMessage());
            http_response_code(503);
            exit('Service temporarily unavailable. Please try again later.');
        }
    }

    /**
     * Loads key=value pairs from the nearest .env file into $_ENV.
     * Searches from this file's directory up to the project root.
     * Skips lines that are blank or start with #.
     * Already-set values are NOT overwritten.
     */
    private static function loadEnv(): void
    {
        // Walk up the directory tree to find .env
        $dir = __DIR__;
        $envFile = null;

        for ($i = 0; $i < 5; $i++) {
            $candidate = $dir . DIRECTORY_SEPARATOR . '.env';
            if (file_exists($candidate)) {
                $envFile = $candidate;
                break;
            }
            $parent = dirname($dir);
            if ($parent === $dir) break; // reached filesystem root
            $dir = $parent;
        }

        if ($envFile === null) {
            error_log('[DB] .env file not found. Using defaults or system env vars.');
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Must contain an = sign
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip surrounding quotes (single or double)
            if (
                strlen($value) >= 2 &&
                (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                )
            ) {
                $value = substr($value, 1, -1);
            }

            // Only set if not already defined
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }

    /** Prevent cloning */
    private function __clone() {}

    /** Prevent unserialization */
    public function __wakeup(): never
    {
        throw new \RuntimeException('Cannot unserialize a singleton.');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }
}