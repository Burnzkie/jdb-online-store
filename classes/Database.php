<?php

declare(strict_types=1);

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
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4;connect_timeout=10',
            $host, $port, $db
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => self::isLocal() ? false : true,
            PDO::MYSQL_ATTR_SSL_CA => self::isLocal() ? '' : ($_ENV['DB_SSL_CA'] ?? ''),
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

   
    private static function isLocal(): bool
    {
        return ($_ENV['APP_ENV'] ??  getenv('APP_ENV') ?? 'production') === 'local';
    }

    
    private static function loadEnv(): void
    {
        $envFileName = self::isLocal() ? '.env' : 'env.production';

        
        $dir     = __DIR__;
        $envFile = null;

        for ($i = 0; $i < 5; $i++) {
            $candidate = $dir . DIRECTORY_SEPARATOR . $envFileName;
            if (file_exists($candidate)) {
                $envFile = $candidate;
                break;
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }

        if ($envFile === null) {
            error_log("[DB] {$envFileName} not found. Using defaults or system env vars.");
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

           
            if (
                strlen($value) >= 2 &&
                (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                )
            ) {
                $value = substr($value, 1, -1);
            }

            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }

   
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