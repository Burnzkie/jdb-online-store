<?php
/**
 * SocialAuth
 * ──────────────────────────────────────────────────────────────
 * Handles Google, Facebook & GitHub OAuth 2.0 without any
 * third-party library — pure cURL only.
 *
 * DB assumptions (adjust column names if yours differ):
 *   users               → id, full_name, email, password, role, created_at
 *   user_social_accounts→ id, user_id, provider, provider_id, created_at
 */
class SocialAuth
{
    private PDO   $pdo;
    private array $config;

    public function __construct(PDO $pdo)
    {
        $this->pdo    = $pdo;
        $this->config = require __DIR__ . '/../config/oauth.php';
    }

    // ── Auth URL Builders ──────────────────────────────────────

    public function getGoogleAuthUrl(): string
    {
        $state = $this->generateState('google');

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $this->config['google']['client_id'],
            'redirect_uri'  => $this->config['google']['redirect_uri'],
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
        ]);
    }

    public function getFacebookAuthUrl(): string
    {
        $state = $this->generateState('facebook');

        return 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query([
            'client_id'     => $this->config['facebook']['client_id'],
            'redirect_uri'  => $this->config['facebook']['redirect_uri'],
            'response_type' => 'code',
            'scope'         => 'email,public_profile',
            'state'         => $state,
        ]);
    }

    public function getGithubAuthUrl(): string
    {
        $state = $this->generateState('github');

        return 'https://github.com/login/oauth/authorize?' . http_build_query([
            'client_id'    => $this->config['github']['client_id'],
            'redirect_uri' => $this->config['github']['redirect_uri'],
            'scope'        => 'read:user user:email',
            'state'        => $state,
        ]);
    }

    // ── Callback Handlers ──────────────────────────────────────

    public function handleGoogleCallback(string $code): array
    {
        $cfg    = $this->config['google'];
        $tokens = $this->curlPost('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri'  => $cfg['redirect_uri'],
            'grant_type'    => 'authorization_code',
        ]);

        if (empty($tokens['access_token'])) {
            throw new RuntimeException('Google token exchange failed: ' . ($tokens['error_description'] ?? 'unknown error'));
        }

        $info = $this->curlGet('https://www.googleapis.com/oauth2/v3/userinfo', $tokens['access_token']);

        return [
            'provider'    => 'google',
            'provider_id' => (string) ($info['sub'] ?? ''),
            'email'       => $info['email']   ?? null,
            'name'        => $info['name']    ?? null,
            'avatar'      => $info['picture'] ?? null,
        ];
    }

    public function handleFacebookCallback(string $code): array
    {
        $cfg    = $this->config['facebook'];
        $tokens = $this->curlPost('https://graph.facebook.com/v19.0/oauth/access_token', [
            'code'          => $code,
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri'  => $cfg['redirect_uri'],
        ]);

        if (empty($tokens['access_token'])) {
            throw new RuntimeException('Facebook token exchange failed: ' . ($tokens['error']['message'] ?? 'unknown error'));
        }

        $info = $this->curlGet(
            'https://graph.facebook.com/me?fields=id,name,email,picture.type(large)',
            $tokens['access_token']
        );

        return [
            'provider'    => 'facebook',
            'provider_id' => (string) ($info['id'] ?? ''),
            'email'       => $info['email']                  ?? null,
            'name'        => $info['name']                   ?? null,
            'avatar'      => $info['picture']['data']['url'] ?? null,
        ];
    }

    public function handleGithubCallback(string $code): array
    {
        $cfg    = $this->config['github'];
        $tokens = $this->curlPost('https://github.com/login/oauth/access_token', [
            'code'          => $code,
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri'  => $cfg['redirect_uri'],
        ]);

        if (empty($tokens['access_token'])) {
            throw new RuntimeException('GitHub token exchange failed.');
        }

        // Get profile
        $info = $this->curlGet('https://api.github.com/user', $tokens['access_token']);

        // GitHub may hide email — fetch from the emails endpoint if null
        $email = $info['email'] ?? null;
        if (empty($email)) {
            $emails = $this->curlGet('https://api.github.com/user/emails', $tokens['access_token']);
            foreach ($emails as $e) {
                if (!empty($e['primary']) && !empty($e['verified'])) {
                    $email = $e['email'];
                    break;
                }
            }
        }

        return [
            'provider'    => 'github',
            'provider_id' => (string) ($info['id'] ?? ''),
            'email'       => $email,
            'name'        => $info['name'] ?? $info['login'] ?? null,
            'avatar'      => $info['avatar_url'] ?? null,
        ];
    }

    // ── DB: Find Existing User Only ───────────────────────────

    /**
     * Looks up an already-registered user by social provider.
     *
     * 1. Match by provider_id  → returning social user, log in directly.
     * 2. Match by email        → existing account, link provider then log in.
     * 3. No match              → returns null (user must register first).
     *
     * @return array|null  Full user row, or null if not registered.
     */
    public function findUser(array $social): array|null
    {
        // 1. Already linked this social account
        $stmt = $this->pdo->prepare(
            'SELECT u.* FROM users u
             INNER JOIN user_social_accounts sa ON sa.user_id = u.id
             WHERE sa.provider = :provider AND sa.provider_id = :provider_id
             LIMIT 1'
        );
        $stmt->execute([':provider' => $social['provider'], ':provider_id' => $social['provider_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) return $user;

        // 2. Email matches a registered account → link provider for next time
        if (!empty($social['email'])) {
            $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $social['email']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $this->linkSocialAccount($user['id'], $social);
                return $user;
            }
        }

        // 3. Not registered — caller must redirect to register page
        return null;
    }

    // ── State Management (CSRF) ────────────────────────────────

    private function generateState(string $provider): string
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state']    = $state;
        $_SESSION['oauth_provider'] = $provider;
        return $state;
    }

    public function validateState(string $returnedState): bool
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $stored = $_SESSION['oauth_state'] ?? '';
        unset($_SESSION['oauth_state'], $_SESSION['oauth_provider']);
        return $stored !== '' && hash_equals($stored, $returnedState);
    }

    // ── Private Helpers ────────────────────────────────────────

    private function linkSocialAccount(string|int $userId, array $social): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO user_social_accounts (user_id, provider, provider_id, created_at)
             VALUES (:user_id, :provider, :provider_id, NOW())'
        );
        $stmt->execute([
            ':user_id'     => $userId,
            ':provider'    => $social['provider'],
            ':provider_id' => $social['provider_id'],
        ]);
    }

    private function curlPost(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',         // GitHub requires this
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new RuntimeException('cURL error: ' . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, true) ?? [];
    }

    private function curlGet(string $url, string $accessToken): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $accessToken",
                'Accept: application/json',
                'User-Agent: jdbshop-app',          // GitHub requires a User-Agent
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new RuntimeException('cURL error: ' . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, true) ?? [];
    }
}