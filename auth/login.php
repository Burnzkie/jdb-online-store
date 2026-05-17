<?php
session_start();
require_once '../classes/Database.php';
require_once '../classes/Security.php';
require_once '../classes/User.php';

$pdo  = Database::getInstance()->getConnection();
$user = new User($pdo);

// Redirect already-logged-in users
if (User::isLoggedIn()) {
    $dest = match ($_SESSION['user_role'] ?? '') {
        'admin'    => '../admin/dashboard.php',
        'staff'    => '../staff/public/dashboard.php',
        default    => '../customer/dashboard.php',
    };
    header("Location: $dest");
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error        = '';
$emailValue   = htmlspecialchars($_COOKIE['remember_email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic CSRF check
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        // Simple session-based rate limiting (5 attempts / 10 min)
        $_SESSION['login_attempts']  = $_SESSION['login_attempts']  ?? 0;
        $_SESSION['login_last_time'] = $_SESSION['login_last_time'] ?? time();

        if (time() - $_SESSION['login_last_time'] > 600) {
            $_SESSION['login_attempts']  = 0;
            $_SESSION['login_last_time'] = time();
        }

        if ($_SESSION['login_attempts'] >= 5) {
            $remaining = 600 - (time() - $_SESSION['login_last_time']);
            $error = 'Too many login attempts. Please wait ' . ceil($remaining / 60) . ' minute(s).';
        } else {
            $email    = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $remember = isset($_POST['remember']);

            if (empty($email) || empty($password)) {
                $error = 'Email and password are required.';
            } else {
                $result = $user->login($email, $password);

                if ($result['success']) {
                    $_SESSION['login_attempts'] = 0;
                    $user->startSession($result['user'], $remember);

                    $dest = match ($result['user']['role']) {
                        'admin'  => '../admin/dashboard.php',
                        'staff'  => '../staff/public/dashboard.php',
                        default  => '../customer/dashboard.php',
                    };
                    header("Location: $dest");
                    exit;
                } else {
                    $_SESSION['login_attempts']++;
                    $error = $result['message'];
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Sign In — JDB Parts'; require_once __DIR__ . '/partials/head.php'; ?>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:         #0d0d0d;
            --surface:    #161616;
            --card:       #1e1e1e;
            --border:     #2e2e2e;
            --accent:     #f0c040;
            --accent2:    #e8853d;
            --text:       #f0ede8;
            --muted:      #888;
            --danger:     #ff5e5e;
            --success:    #5effa3;
            --radius:     14px;
            --transition: 0.22s cubic-bezier(.4,0,.2,1);
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        /* Ambient blobs */
        body::before {
            content: '';
            position: fixed;
            top: -200px; left: -200px;
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(240,192,64,.12) 0%, transparent 70%);
            pointer-events: none;
        }
        body::after {
            content: '';
            position: fixed;
            bottom: -200px; right: -200px;
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(232,133,61,.10) 0%, transparent 70%);
            pointer-events: none;
        }

        /* ── Layout ────────────────────────────────── */
        .wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            max-width: 900px;
            width: 100%;
            background: var(--card);
            border-radius: 24px;
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: 0 40px 80px rgba(0,0,0,.5);
            animation: slideUp .5s ease both;
        }

        @keyframes slideUp {
            from { opacity:0; transform:translateY(32px); }
            to   { opacity:1; transform:translateY(0); }
        }

        /* ── Left brand panel ───────────────────────── */
        .panel-left {
            background: linear-gradient(160deg, #1a1400 0%, #0d0d0d 100%);
            padding: 3rem 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-right: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }
        .panel-left::before {
            content: '';
            position: absolute;
            top: -80px; left: -80px;
            width: 340px; height: 340px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(240,192,64,.15) 0%, transparent 70%);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: .75rem;
            position: relative;
        }
        .brand-icon {
            width: 42px; height: 42px;
            background: var(--accent);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem;
            color: #000;
        }
        .brand-name {
            font-family: 'Syne', sans-serif;
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -.03em;
            color: var(--text);
        }
        .brand-name span { color: var(--accent); }

        .panel-pitch { position: relative; }
        .panel-pitch h2 {
            font-family: 'Syne', sans-serif;
            font-size: 2rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 1rem;
        }
        .panel-pitch h2 em {
            font-style: normal;
            color: var(--accent);
        }
        .panel-pitch p {
            color: var(--muted);
            font-size: .95rem;
            line-height: 1.6;
            margin-bottom: 1.75rem;
        }

        .perks { list-style: none; display: flex; flex-direction: column; gap: .75rem; }
        .perks li {
            display: flex; align-items: center; gap: .75rem;
            font-size: .9rem; color: #aaa;
        }
        .perks li i {
            width: 28px; height: 28px;
            background: rgba(240,192,64,.12);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: .8rem;
            color: var(--accent);
            flex-shrink: 0;
        }

        /* ── Right form panel ───────────────────────── */
        .panel-right {
            padding: 3rem 2.5rem;
            overflow-y: auto;
            max-height: 90vh;
        }

        .panel-right h3 {
            font-family: 'Syne', sans-serif;
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: .25rem;
        }
        .panel-right .sub {
            color: var(--muted);
            font-size: .9rem;
            margin-bottom: 1.75rem;
        }

        /* ── Alert ──────────────────────────────────── */
        .alert {
            display: flex; align-items: flex-start; gap: .75rem;
            padding: .875rem 1rem;
            border-radius: var(--radius);
            margin-bottom: 1.25rem;
            font-size: .88rem;
            animation: fadeIn .3s ease;
        }
        @keyframes fadeIn { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
        .alert-danger   { background: rgba(255,94,94,.1);  border: 1px solid rgba(255,94,94,.3);  color: #ff9090; }
        .alert-warning  { background: rgba(232,133,61,.1); border: 1px solid rgba(232,133,61,.3); color: #f0a870; }
        .alert i { margin-top: 1px; flex-shrink: 0; }

        /* ── Form fields ────────────────────────────── */
        .field { display: flex; flex-direction: column; margin-bottom: .85rem; }
        .field label {
            font-size: .8rem;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: .4rem;
        }
        .field .label-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: .4rem;
        }
        .field .label-row label { margin-bottom: 0; }
        .field .label-row a {
            font-size: .78rem;
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
            transition: opacity var(--transition);
        }
        .field .label-row a:hover { opacity: .75; text-decoration: underline; }

        .field input {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: .72rem 1rem;
            color: var(--text);
            font-size: .95rem;
            font-family: inherit;
            transition: var(--transition);
            outline: none;
        }
        .field input::placeholder { color: #555; }
        .field input:focus {
            border-color: var(--accent);
            background: #1a1a1a;
            box-shadow: 0 0 0 3px rgba(240,192,64,.1);
        }

        .field .input-wrap { position: relative; }
        .field .input-wrap input { width: 100%; padding-right: 2.75rem; }
        .field .input-wrap .toggle-pw {
            position: absolute; right: .85rem; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: var(--muted); cursor: pointer; padding: 0;
            font-size: 1rem; transition: color var(--transition);
        }
        .field .input-wrap .toggle-pw:hover { color: var(--text); }

        /* ── Remember me ────────────────────────────── */
        .check-row {
            display: flex;
            align-items: center;
            gap: .6rem;
            margin-bottom: 1.25rem;
        }
        .check-row input[type="checkbox"] {
            width: 16px; height: 16px;
            accent-color: var(--accent);
            cursor: pointer;
            flex-shrink: 0;
        }
        .check-row label {
            font-size: .875rem;
            color: var(--muted);
            cursor: pointer;
            user-select: none;
        }

        /* ── Submit button ──────────────────────────── */
        .btn-submit {
            width: 100%;
            padding: .9rem 1.5rem;
            background: var(--accent);
            color: #000;
            font-family: 'Syne', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            margin-top: .5rem;
            transition: var(--transition);
            display: flex; align-items: center; justify-content: center; gap: .5rem;
            letter-spacing: .01em;
        }
        .btn-submit:hover {
            background: #f5ce60;
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(240,192,64,.25);
        }
        .btn-submit:active { transform: translateY(0); }

        /* ── Bottom link ────────────────────────────── */
        .register-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: .875rem;
            color: var(--muted);
        }
        .register-link a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }
        .register-link a:hover { text-decoration: underline; }

        /* ── Divider ────────────────────────────────── */
        .divider {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin: 1.5rem 0;
            color: var(--muted);
            font-size: .8rem;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        /* ── Responsive ─────────────────────────────── */
        @media (max-width: 700px) {
            .wrapper { grid-template-columns: 1fr; }
            .panel-left { display: none; }
            .panel-right { max-height: none; padding: 2rem 1.5rem; }
        }
    </style>
</head>
<body>

<div class="wrapper">

    <!-- ── Left Brand Panel ──────────────────────── -->
    <div class="panel-left">
        <div class="brand">
            <div class="brand-icon"><i class="bi bi-gear-wide-connected"></i></div>
            <div class="brand-name">JDB<span>Parts</span></div>
        </div>
      
        <div class="panel-pitch">
            <h2>Welcome <em>back</em> to your account.</h2>
            <p>Sign in to manage your orders, track deliveries, and access thousands of quality auto parts.</p>
            <ul class="perks">
                <li><i class="bi bi-lightning-charge-fill"></i> Fast order processing &amp; tracking</li>
                <li><i class="bi bi-shield-check-fill"></i> Verified OEM &amp; aftermarket parts</li>
                <li><i class="bi bi-truck"></i> Nationwide delivery network</li>
                <li><i class="bi bi-headset"></i> Dedicated support team</li>
            </ul>
        </div>

        <div style="font-size:.78rem; color:#444;">
            &copy; <?= date('Y') ?> JDB Parts. All rights reserved.
        </div>
    </div>

    <!-- ── Right Form Panel ──────────────────────── -->
    <div class="panel-right">
        <h3>Sign in</h3>
        <p class="sub">Enter your credentials to continue.</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($_GET['error']) && $_GET['error'] === 'access_denied'): ?>
            <div class="alert alert-warning" role="alert">
                <i class="bi bi-lock-fill"></i>
                <span>You must be logged in to access that page.</span>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <div class="field">
                <label for="email">Email Address <span style="color:var(--accent)">*</span></label>
                <input type="email" id="email" name="email"
                       value="<?= $emailValue ?>"
                       placeholder="you@example.com"
                       autocomplete="username" required autofocus>
            </div>

            <div class="field">
                <div class="label-row">
                    <label for="password">Password <span style="color:var(--accent)">*</span></label>
                    <a href="forgot-password.php">Forgot password?</a>
                </div>
                <div class="input-wrap">
                    <input type="password" id="password" name="password"
                           placeholder="Your password"
                           autocomplete="current-password" required>
                    <button type="button" class="toggle-pw" data-target="password" aria-label="Show password">
                        <i class="bi bi-eye" id="pwIcon"></i>
                    </button>
                </div>
            </div>

            <div class="check-row">
                <input type="checkbox" id="remember" name="remember">
                <label for="remember">Remember me for 30 days</label>
            </div>

            <button type="submit" class="btn-submit">
                <i class="bi bi-box-arrow-in-right"></i>
                Sign In
            </button>
        </form>

        <div class="divider">or</div>

        <p class="register-link">
            Don't have an account? <a href="register.php">Create one here</a>
        </p>
    </div>
</div>

<script>
document.querySelectorAll('.toggle-pw').forEach(btn => {
    btn.addEventListener('click', function () {
        const inp  = document.getElementById(this.dataset.target);
        const icon = this.querySelector('i');
        if (!inp) return;
        const show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
});
</script>

</body>
</html>