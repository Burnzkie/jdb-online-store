<?php
/**
 * Security.php
 * Centralized security functions for CSRF protection, rate limiting, and input validation
 */

declare(strict_types=1);

class Security {
    
    /**
     * Generate CSRF token
     */
    public static function generateCsrfToken(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Validate CSRF token
     */
    public static function validateCsrfToken(?string $token): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        
        if (empty($sessionToken) || empty($token)) {
            return false;
        }
        
        return hash_equals($sessionToken, $token);
    }
    
    /**
     * Get CSRF token input field HTML
     */
    public static function csrfField(): string {
        $token = self::generateCsrfToken();
        return sprintf(
            '<input type="hidden" name="csrf_token" value="%s">',
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
        );
    }
    
    /**
     * Validate CSRF token from POST request
     * Dies with error if invalid
     */
    public static function requireCsrfToken(): void {
        $token = $_POST['csrf_token'] ?? '';
        
        if (!self::validateCsrfToken($token)) {
            self::logSecurityEvent('CSRF token validation failed');
            http_response_code(403);
            die('Security validation failed. Please refresh and try again.');
        }
    }
    
    /**
     * Rate limiting using APCu
     * 
     * @param string $key Unique identifier for rate limit
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $decaySeconds Time window in seconds
     * @return bool True if allowed, false if rate limit exceeded
     */
    public static function checkRateLimit(
        string $key, 
        int $maxAttempts = 5, 
        int $decaySeconds = 300
    ): bool {
        if (!function_exists('apcu_fetch')) {
            // APCu not available, allow by default
            return true;
        }
        
        $cacheKey = 'rate_limit_' . $key;
        $attempts = apcu_fetch($cacheKey);
        
        if ($attempts === false) {
            $attempts = 0;
        }
        
        if ($attempts >= $maxAttempts) {
            self::logSecurityEvent('Rate limit exceeded', ['key' => $key]);
            return false;
        }
        
        apcu_store($cacheKey, $attempts + 1, $decaySeconds);
        return true;
    }
    
    /**
     * Reset rate limit counter
     */
    public static function resetRateLimit(string $key): void {
        if (function_exists('apcu_delete')) {
            apcu_delete('rate_limit_' . $key);
        }
    }
    
    /**
     * Sanitize string input
     */
    public static function sanitizeString(
        string $input, 
        int $maxLength = 255
    ): string {
        $input = trim($input);
        $input = strip_tags($input);
        
        if (strlen($input) > $maxLength) {
            $input = substr($input, 0, $maxLength);
        }
        
        return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Sanitize and validate email
     */
    public static function sanitizeEmail(string $email): ?string {
        $email = trim($email);
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        
        return $email;
    }
    
    /**
     * Sanitize integer input
     */
    public static function sanitizeInt($input): ?int {
        $value = filter_var($input, FILTER_VALIDATE_INT);
        return $value !== false ? $value : null;
    }
    
    /**
     * Sanitize float input
     */
    public static function sanitizeFloat($input): ?float {
        $value = filter_var($input, FILTER_VALIDATE_FLOAT);
        return $value !== false ? $value : null;
    }
    
    /**
     * Validate phone number format
     */
    public static function validatePhone(string $phone): bool {
        // Allow digits, spaces, hyphens, plus, and parentheses
        return (bool)preg_match('/^[0-9\s\-\+\(\)]{7,20}$/', $phone);
    }
    
    /**
     * Validate password strength
     */
    public static function validatePassword(string $password): array {
        $errors = [];
        
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters';
        }
        
        if (strlen($password) > 128) {
            $errors[] = 'Password must be less than 128 characters';
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }
        
        // Check against common passwords
        $commonPasswords = [
            'password', '12345678', 'qwerty', 'abc123', 'password123',
            'admin', 'letmein', 'welcome', 'monkey', '1234567890'
        ];
        
        if (in_array(strtolower($password), $commonPasswords)) {
            $errors[] = 'Password is too common';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Hash password securely
     */
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    /**
     * Verify password against hash
     */
    public static function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }
    
    /**
     * Regenerate session ID to prevent fixation
     */
    public static function regenerateSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
    }
    
    /**
     * Set security headers
     */
    public static function setSecurityHeaders(): void {
        // Prevent clickjacking
        header('X-Frame-Options: DENY');
        
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // Enable XSS protection
        header('X-XSS-Protection: 1; mode=block');
        
        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Content Security Policy (adjust as needed)
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "img-src 'self' data: https:",
            "font-src 'self' https://cdnjs.cloudflare.com",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'"
        ]);
        header("Content-Security-Policy: $csp");
        
        // HTTPS only (uncomment in production)
        // header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    
    /**
     * Validate URL for safe redirect
     */
    public static function isSafeRedirect(string $url): bool {
        // Only allow relative URLs or same-origin URLs
        $parsed = parse_url($url);
        
        // Relative URL (no host) is safe
        if (!isset($parsed['host'])) {
            return true;
        }
        
        // Check if same origin
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        return $parsed['host'] === $currentHost;
    }
    
    /**
     * Safe redirect
     */
    public static function redirect(string $location): void {
        if (!self::isSafeRedirect($location)) {
            $location = '/'; // Fallback to home
        }
        
        header("Location: $location");
        exit;
    }
    
    /**
     * Log security events
     */
    public static function logSecurityEvent(
        string $message, 
        array $context = []
    ): void {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => 'SECURITY',
            'message' => $message,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'user_id' => $_SESSION['user_id'] ?? null,
            'context' => $context
        ];
        
        error_log(json_encode($logData));
    }
    
    /**
     * Check if user is authenticated
     */
    public static function isAuthenticated(): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
    }
    
    /**
     * Require authentication (die if not logged in)
     */
    public static function requireAuth(string $requiredRole = null): void {
        if (!self::isAuthenticated()) {
            $_SESSION['flash'] = [
                'type' => 'warning',
                'message' => 'Please log in to continue'
            ];
            header('Location: /login.php');
            exit;
        }
        
        if ($requiredRole && $_SESSION['user_role'] !== $requiredRole) {
            http_response_code(403);
            die('Access denied');
        }
    }
    
    /**
     * Escape output for HTML
     */
    public static function escape(?string $value): string {
        if ($value === null) {
            return '';
        }
        
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Escape output for HTML attribute
     */
    public static function escapeAttr(?string $value): string {
        if ($value === null) {
            return '';
        }
        
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Escape output for JavaScript
     */
    public static function escapeJs(?string $value): string {
        if ($value === null) {
            return '';
        }
        
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
    
    /**
     * Clean filename for upload
     */
    public static function sanitizeFilename(string $filename): string {
        // Remove any path components
        $filename = basename($filename);
        
        // Remove special characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        
        // Limit length
        if (strlen($filename) > 255) {
            $filename = substr($filename, 0, 255);
        }
        
        return $filename;
    }
    
    /**
     * Validate file upload
     */
    public static function validateFileUpload(
        array $file,
        array $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'],
        int $maxSize = 5242880 // 5MB
    ): array {
        if (!isset($file['error'])) {
            return [
                'valid' => false,
                'message' => 'Invalid file upload'
            ];
        }
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [
                'valid' => false,
                'message' => 'File upload error: ' . $file['error']
            ];
        }
        
        if ($file['size'] > $maxSize) {
            $maxMB = round($maxSize / 1048576, 2);
            return [
                'valid' => false,
                'message' => "File size exceeds maximum of {$maxMB}MB"
            ];
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            return [
                'valid' => false,
                'message' => 'Invalid file type'
            ];
        }
        
        return [
            'valid' => true,
            'message' => 'File is valid',
            'mime_type' => $mimeType
        ];
    }
}