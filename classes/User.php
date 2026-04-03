<?php

declare(strict_types=1);

class User
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ─── Auth ─────────────────────────────────────────────────────────────────

    /**
     * Register a new user.
     *
     * @return array{success: bool, message: string}
     */
    public function register(
        string  $firstname,
        string  $lastname,
        string  $phone,
        string  $email,
        string  $password,
        string  $role     = 'customer',
        ?string $staffId  = null,
        ?string $position = null
    ): array {
        // Duplicate e-mail check
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'This email is already registered.'];
        }

        $strength = Security::validatePassword($password);
        if (!$strength['valid']) {
            return ['success' => false, 'message' => implode(' ', $strength['errors'])];
        }

        $allowedRoles = ['customer', 'staff', 'admin'];
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'customer';
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO users
                (firstname, lastname, phone, email, password_hash, role, staff_id, position, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $result = $stmt->execute([
            $firstname, $lastname, $phone, $email,
            Security::hashPassword($password),
            $role, $staffId, $position,
        ]);

        return $result
            ? ['success' => true,  'message' => 'Account created successfully!']
            : ['success' => false, 'message' => 'Registration failed. Please try again.'];
    }

    /**
     * Authenticate a user by email and password.
     *
     * @return array{success: bool, user?: array, message: string}
     */
    public function login(string $email, string $password): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !Security::verifyPassword($password, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Invalid email or password.'];
        }

        if (!empty($user['is_banned'])) {
            return ['success' => false, 'message' => 'Your account has been suspended. Please contact support.'];
        }

        return ['success' => true, 'user' => $user, 'message' => ''];
    }

    /** Populate $_SESSION after a successful login. */
    public function startSession(array $user, bool $remember = false): void
    {
        session_regenerate_id(true);

        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_name']  = $user['firstname'] . ' ' . $user['lastname'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role']  = $user['role'];
        $_SESSION['initiated']  = true;

        if ($remember) {
            setcookie(
                'remember_email',
                $user['email'],
                time() + (86400 * 30),
                '/',
                '',
                true,  // secure
                true   // httpOnly
            );
        }
    }

    // ─── Static helpers ───────────────────────────────────────────────────────

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function redirectIfLoggedIn(string $location = '../customer/dashboard.php'): void
    {
        if (self::isLoggedIn()) {
            header('Location: ' . $location);
            exit;
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '',
                time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();

        if (isset($_COOKIE['remember_email'])) {
            setcookie('remember_email', '', time() - 3600, '/');
        }
    }

    // ─── Instance reads / writes ──────────────────────────────────────────────

    public function getUserById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, firstname, lastname, email, phone, role,
                   password_hash, staff_id, position, profile_picture,
                   created_at, updated_at, email_verified, is_banned
            FROM   users
            WHERE  id = ?
            LIMIT  1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getUserByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateUserProfile(
        int     $userId,
        string  $firstname,
        string  $lastname,
        string  $email,
        ?string $phone = null
    ): bool {
        $sql    = "UPDATE users SET firstname = :fn, lastname = :ln, email = :em, updated_at = NOW()";
        $params = [':fn' => $firstname, ':ln' => $lastname, ':em' => $email, ':id' => $userId];

        if ($phone !== null) {
            $sql .= ', phone = :ph';
            $params[':ph'] = $phone;
        }

        $sql .= ' WHERE id = :id';
        return $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * Update (or clear) the profile picture path.
     * Pass null to remove the current picture.
     */
    public function updateProfilePicture(int $userId, ?string $path): bool
    {
        return $this->pdo->prepare(
            "UPDATE users SET profile_picture = :p, updated_at = NOW() WHERE id = :id"
        )->execute([':p' => $path, ':id' => $userId]);
    }

    /** Soft-deactivate the account via the ban mechanism. */
    public function deactivateUser(int $userId): bool
    {
        return $this->pdo->prepare("
            UPDATE users
            SET    is_banned  = 1,
                   banned_at  = NOW(),
                   ban_reason = 'User self-deactivated account',
                   updated_at = NOW()
            WHERE  id = :id
        ")->execute([':id' => $userId]);
    }
}