<?php
declare(strict_types=1);

/**
 * Env.php — Simple .env loader.
 * Loads key=value pairs from a .env file into $_ENV and getenv().
 * Place this file in your /classes directory.
 *
 * Usage (once, near the top of any entry point):
 *   require_once '../classes/Env.php';
 *   Env::load(__DIR__ . '/../.env');
 */
class Env
{
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) return;   // only load once per request

        if (!file_exists($path)) {
            error_log("Env::load — .env file not found at: $path");
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments
            if ($line === '' || str_starts_with($line, '#')) continue;

            // Must contain '='
            if (!str_contains($line, '=')) continue;

            [$key, $value] = explode('=', $line, 2);

            $key   = trim($key);
            $value = trim($value);

            // Strip inline comments (e.g. KEY=value # comment)
            if (str_contains($value, ' #')) {
                $value = trim(explode(' #', $value, 2)[0]);
            }

            // Strip surrounding quotes if present
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            // Strip trailing backslash (common copy-paste mistake)
            $value = rtrim($value, '\\');

            if ($key === '') continue;

            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
            putenv("$key=$value");
        }

        self::$loaded = true;
    }

    /**
     * Get an env value with an optional default.
     */
    public static function get(string $key, string $default = ''): string
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
}