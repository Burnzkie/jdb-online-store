<?php
session_start();
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/SocialAuth.php';

$pdo        = Database::getInstance()->getConnection();
$socialAuth = new SocialAuth($pdo);
$user       = new User($pdo);

// ── Validate state (CSRF) ──────────────────────────────────────
$returnedState = $_GET['state'] ?? '';

if (!$socialAuth->validateState($returnedState)) {
    $_SESSION['social_error'] = 'Invalid OAuth state. Please try again.';
    header('Location: login.php');
    exit;
}

// ── User denied access ─────────────────────────────────────────
if (isset($_GET['error'])) {
    $_SESSION['social_error'] = 'GitHub sign-in was cancelled.';
    header('Location: login.php');
    exit;
}

$code = $_GET['code'] ?? '';
if (empty($code)) {
    $_SESSION['social_error'] = 'No authorisation code received from GitHub.';
    header('Location: login.php');
    exit;
}

// ── Exchange code → user info → DB user ───────────────────────
try {
    $socialUser = $socialAuth->handleGithubCallback($code);
    $dbUser     = $socialAuth->findUser($socialUser);

    if ($dbUser === null) {
        $_SESSION['social_error'] = 'No account found. Please register first, then link your Github via your profile settings.';
        header('Location: login.php');
        exit;
    }
} catch (Throwable $e) {
    error_log('[GitHub OAuth] ' . $e->getMessage());
    $_SESSION['social_error'] = 'GitHub sign-in failed. Please try again.';
    header('Location: login.php');
    exit;
}

// ── Start session (same as normal login) ──────────────────────
$user->startSession($dbUser, false);

$dest = match ($dbUser['role'] ?? 'customer') {
    'admin' => '../admin/dashboard.php',
    'staff' => '../staff/public/dashboard.php',
    default => '../customer/dashboard.php',
};

header("Location: $dest");
exit;