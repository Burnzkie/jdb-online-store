<?php
session_start();
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Security.php';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo   = Database::getInstance()->getConnection();
$user  = new User($pdo);
$error = '';

// Preserve form values on error
$firstname = '';
$lastname  = '';
$phone     = '';
$email     = '';

// Address fields (preserved on error)
$addrStreet   = '';
$addrBarangay = '';
$addrCity     = '';
$addrProvince = '';
$addrPostal   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $role = $_POST['role'] ?? 'customer';

        $firstname = trim($_POST['firstname'] ?? '');
        $lastname  = trim($_POST['lastname']  ?? '');
        $phone     = trim($_POST['phone']     ?? '');
        $email     = trim($_POST['email']     ?? '');
        $password  = $_POST['password']         ?? '';
        $confirm   = $_POST['password_confirm'] ?? '';

        // Address (optional — customer only)
        $addrStreet   = trim($_POST['address_street']   ?? '');
        $addrBarangay = trim($_POST['address_barangay'] ?? '');
        $addrCity     = trim($_POST['address_city']     ?? '');
        $addrProvince = trim($_POST['address_province'] ?? '');
        $addrPostal   = trim($_POST['address_postal']   ?? '');

        if (empty($firstname) || empty($lastname) || empty($phone) || empty($email) || empty($password)) {
            $error = 'All required fields must be filled.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            if ($role === 'customer') {
                $result = $user->register(
                    $firstname, $lastname, $phone, $email, $password,
                    'customer', null, null,
                    $addrStreet   ?: null,
                    $addrBarangay ?: null,
                    $addrCity     ?: null,
                    $addrProvince ?: null,
                    $addrPostal   ?: null
                );

                if ($result['success']) {
                    $loginResult = $user->login($email, $password);
                    if ($loginResult['success']) {
                        $user->startSession($loginResult['user']);
                        header('Location: ../customer/dashboard.php');
                        exit;
                    }
                } else {
                    $error = $result['message'];
                }
            } elseif ($role === 'staff') {
                $staff_id      = trim($_POST['staff_id']      ?? '');
                $position      = trim($_POST['position']      ?? '');
                $approval_code = $_POST['approval_code']      ?? '';

                if (empty($staff_id) || empty($position) || empty($approval_code)) {
                    $error = 'All staff fields are required.';
                } elseif ($approval_code !== ($_ENV['STAFF_APPROVAL_CODE'] ?? getenv('STAFF_APPROVAL_CODE'))) {
                    $error = 'Invalid staff approval code.';
                } else {
                    $result = $user->register(
                        $firstname, $lastname, $phone, $email, $password,
                        'staff', $staff_id, $position
                    );

                    if ($result['success']) {
                        $loginResult = $user->login($email, $password);
                        if ($loginResult['success']) {
                            $user->startSession($loginResult['user']);
                            header('Location: ../staff/public/dashboard.php');
                            exit;
                        }
                    } else {
                        $error = $result['message'];
                    }
                }
            } else {
                $error = 'Invalid role selected.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — JDB Parts</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=Syne:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:        #0d0d0d;
            --surface:   #161616;
            --card:      #1e1e1e;
            --border:    #2e2e2e;
            --accent:    #f0c040;
            --accent2:   #e8853d;
            --text:      #f0ede8;
            --muted:     #888;
            --danger:    #ff5e5e;
            --success:   #5effa3;
            --radius:    14px;
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
            background: radial-gradient(circle, rgba(232,133,61,.1) 0%, transparent 70%);
            pointer-events: none;
        }

        .wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            max-width: 980px;
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

        .brand { display: flex; align-items: center; gap: .75rem; position: relative; }
        .brand-icon {
            width: 42px; height: 42px;
            background: var(--accent);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; color: #000;
        }
        .brand-name {
            font-family: 'Syne', sans-serif;
            font-size: 1.5rem; font-weight: 800;
            letter-spacing: -.03em; color: var(--text);
        }
        .brand-name span { color: var(--accent); }

        .panel-pitch { position: relative; }
        .panel-pitch h2 {
            font-family: 'Syne', sans-serif;
            font-size: 2rem; font-weight: 800;
            line-height: 1.2; margin-bottom: 1rem;
        }
        .panel-pitch h2 em { font-style: normal; color: var(--accent); }
        .panel-pitch p { color: var(--muted); font-size: .95rem; line-height: 1.6; margin-bottom: 1.75rem; }

        .perks { list-style: none; display: flex; flex-direction: column; gap: .75rem; }
        .perks li { display: flex; align-items: center; gap: .75rem; font-size: .9rem; color: #aaa; }
        .perks li i {
            width: 28px; height: 28px;
            background: rgba(240,192,64,.12);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: var(--accent); font-size: .85rem;
        }

        /* ── Right panel ─────────────── */
        .panel-right {
            padding: 3rem 2.5rem;
            overflow-y: auto;
            max-height: 100vh;
        }
        .panel-right h3 {
            font-family: 'Syne', sans-serif;
            font-size: 1.75rem; font-weight: 800;
            margin-bottom: .25rem;
        }
        .sub { color: var(--muted); font-size: .9rem; margin-bottom: 1.5rem; }

        /* Role toggle */
        .role-toggle {
            display: flex; gap: .5rem;
            background: var(--surface);
            border-radius: 12px; padding: .35rem;
            margin-bottom: 1.5rem;
        }
        .role-btn {
            flex: 1; padding: .55rem 1rem;
            border: none; border-radius: 9px;
            background: none; color: var(--muted);
            font-family: inherit; font-size: .9rem;
            cursor: pointer; transition: var(--transition);
            display: flex; align-items: center; justify-content: center; gap: .4rem;
        }
        .role-btn.active { background: var(--card); color: var(--text); box-shadow: 0 2px 6px rgba(0,0,0,.3); }

        /* Alert */
        .alert {
            display: flex; align-items: flex-start; gap: .75rem;
            padding: .85rem 1rem; border-radius: 10px;
            margin-bottom: 1.25rem; font-size: .9rem;
        }
        .alert-danger { background: rgba(255,94,94,.1); color: var(--danger); border: 1px solid rgba(255,94,94,.2); }

        /* Fields */
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
        .field { display: flex; flex-direction: column; gap: .35rem; margin-bottom: .85rem; }
        .field label { font-size: .875rem; font-weight: 500; color: #ccc; }
        .req { color: var(--accent); }

        .field input, .field select {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: .72rem 1rem;
            color: var(--text);
            font-size: .95rem; font-family: inherit;
            transition: var(--transition); outline: none;
            appearance: auto;
        }
        .field input::placeholder, .field select::placeholder { color: #555; }
        .field input:focus, .field select:focus {
            border-color: var(--accent);
            background: #1a1a1a;
            box-shadow: 0 0 0 3px rgba(240,192,64,.1);
        }
        .field select option { background: var(--card); color: var(--text); }
        .field select:disabled { opacity: .5; cursor: not-allowed; }

        .input-wrap { position: relative; }
        .input-wrap input { width: 100%; padding-right: 2.75rem; }
        .toggle-pw {
            position: absolute; right: .85rem; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: var(--muted);
            cursor: pointer; padding: 0; font-size: 1rem;
            transition: color var(--transition);
        }
        .toggle-pw:hover { color: var(--text); }

        /* Password strength */
        .strength-bar {
            height: 3px; background: var(--border);
            border-radius: 2px; margin-top: .4rem; overflow: hidden;
        }
        .strength-bar-fill { height: 100%; width: 0; transition: width .3s, background .3s; }
        .field-hint { font-size: .78rem; color: var(--muted); margin-top: .2rem; }

        /* Staff section */
        .staff-section {
            display: none;
            background: rgba(240,192,64,.04);
            border: 1px solid rgba(240,192,64,.15);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: .85rem;
        }
        .staff-section.visible { display: block; }
        .staff-label {
            font-size: .8rem; font-weight: 600;
            color: var(--accent); text-transform: uppercase;
            letter-spacing: .05em; margin-bottom: .75rem;
            display: flex; align-items: center; gap: .5rem;
        }

        /* ── Address section ── */
        .address-section {
            background: rgba(240,192,64,.04);
            border: 1px solid rgba(240,192,64,.12);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: .85rem;
        }
        .address-section.hidden { display: none; }
        .address-label {
            font-size: .8rem; font-weight: 600;
            color: var(--accent); text-transform: uppercase;
            letter-spacing: .05em; margin-bottom: .75rem;
            display: flex; align-items: center; gap: .5rem;
        }
        .address-hint {
            font-size: .8rem; color: var(--muted);
            margin-bottom: .85rem; line-height: 1.4;
        }
        #reg-manual-wrap { display: none; }
        #reg-manual-wrap .manual-warning {
            font-size: .8rem; color: #f0a030;
            background: rgba(240,160,48,.08);
            border: 1px solid rgba(240,160,48,.2);
            border-radius: 8px; padding: .6rem .85rem;
            margin-bottom: .5rem;
        }

        /* Submit */
        .btn-submit {
            width: 100%; padding: .9rem 1.5rem;
            background: var(--accent); color: #000;
            font-family: 'Syne', sans-serif;
            font-size: 1rem; font-weight: 700;
            border: none; border-radius: 12px;
            cursor: pointer; margin-top: .5rem;
            transition: var(--transition);
            display: flex; align-items: center; justify-content: center; gap: .5rem;
        }
        .btn-submit:hover {
            background: #f5ce60;
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(240,192,64,.25);
        }
        .btn-submit:active { transform: translateY(0); }

        .login-link {
            text-align: center; margin-top: 1.5rem;
            font-size: .875rem; color: var(--muted);
        }
        .login-link a { color: var(--accent); text-decoration: none; font-weight: 600; }
        .login-link a:hover { text-decoration: underline; }

        @media (max-width: 700px) {
            .wrapper { grid-template-columns: 1fr; }
            .panel-left { display: none; }
            .panel-right { max-height: none; padding: 2rem 1.5rem; }
            .form-row { grid-template-columns: 1fr; }
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
            <h2>Your <em>one-stop</em> auto parts platform.</h2>
            <p>Join thousands of mechanics and car enthusiasts who source quality parts fast, at competitive prices.</p>
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
        <h3>Create your account</h3>
        <p class="sub">Fill in the details below to get started.</p>

        <!-- Role Toggle -->
        <div class="role-toggle">
            <button type="button" class="role-btn active" data-role="customer" id="btnCustomer">
                <i class="bi bi-person"></i> Customer
            </button>
            <button type="button" class="role-btn" data-role="staff" id="btnStaff">
                <i class="bi bi-person-badge"></i> Staff
            </button>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="role" id="roleInput" value="<?= htmlspecialchars($_POST['role'] ?? 'customer') ?>">

            <!-- Name Row -->
            <div class="form-row">
                <div class="field">
                    <label>First Name <span class="req">*</span></label>
                    <input type="text" name="firstname" value="<?= htmlspecialchars($firstname) ?>"
                           placeholder="John" autocomplete="given-name" required>
                </div>
                <div class="field">
                    <label>Last Name <span class="req">*</span></label>
                    <input type="text" name="lastname" value="<?= htmlspecialchars($lastname) ?>"
                           placeholder="Doe" autocomplete="family-name" required>
                </div>
            </div>

            <div class="field">
                <label>Phone Number <span class="req">*</span></label>
                <input type="tel" name="phone" value="<?= htmlspecialchars($phone) ?>"
                       placeholder="+63 912 345 6789" autocomplete="tel" required>
            </div>

            <div class="field">
                <label>Email Address <span class="req">*</span></label>
                <input type="email" name="email" value="<?= htmlspecialchars($email) ?>"
                       placeholder="you@example.com" autocomplete="email" required>
            </div>

            <div class="field">
                <label>Password <span class="req">*</span></label>
                <div class="input-wrap">
                    <input type="password" name="password" id="pw" placeholder="Min. 8 characters"
                           autocomplete="new-password" required minlength="8">
                    <button type="button" class="toggle-pw" data-target="pw" aria-label="Show password">
                        <i class="bi bi-eye" id="pwIcon"></i>
                    </button>
                </div>
                <div class="strength-bar"><div class="strength-bar-fill" id="strengthFill"></div></div>
                <div class="field-hint">Uppercase · lowercase · number · symbol</div>
            </div>

            <div class="field">
                <label>Confirm Password <span class="req">*</span></label>
                <div class="input-wrap">
                    <input type="password" name="password_confirm" id="pwc" placeholder="Repeat your password"
                           autocomplete="new-password" required>
                    <button type="button" class="toggle-pw" data-target="pwc" aria-label="Show password">
                        <i class="bi bi-eye" id="pwcIcon"></i>
                    </button>
                </div>
            </div>

            <!-- ── Customer Address Section ──────────────────── -->
            <div class="address-section" id="addressSection">
                <div class="address-label">
                    <i class="bi bi-geo-alt-fill"></i> Delivery Address
                </div>

                <!-- Street -->
                <div class="field">
                    <label>Street / House No.</label>
                    <input type="text" name="address_street" id="reg-street"
                           value="<?= htmlspecialchars($addrStreet) ?>"
                           placeholder="e.g. 123 Rizal St., Purok 4">
                </div>

                <!-- Province -->
                <div class="form-row">
                    <div class="field">
                        <label>Province</label>
                        <select name="address_province" id="reg-province">
                            <option value="">Loading provinces...</option>
                        </select>
                    </div>
                    <!-- City -->
                    <div class="field">
                        <label>City / Municipality</label>
                        <select name="address_city" id="reg-city" disabled>
                            <option value="">Select province first</option>
                        </select>
                    </div>
                </div>

                <!-- Barangay + Postal -->
                <div class="form-row">
                    <div class="field">
                        <label>Barangay</label>
                        <select name="address_barangay" id="reg-barangay" disabled>
                            <option value="">Select city first</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Postal Code</label>
                        <input type="text" name="address_postal" id="reg-postal"
                               value="<?= htmlspecialchars($addrPostal) ?>"
                               placeholder="e.g. 7113" maxlength="10">
                    </div>
                </div>

                <!-- Manual fallback -->
                <div id="reg-manual-wrap">
                    <div class="manual-warning">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Address lookup unavailable. You can type your full address below.
                    </div>
                    <div class="field">
                        <input type="text" name="address_manual"
                               placeholder="Barangay, City, Province, Postal Code">
                    </div>
                </div>

                <!-- Hidden fields to carry selected text values on POST -->
                <input type="hidden" name="address_city_val"     id="reg-city-hidden">
                <input type="hidden" name="address_barangay_val" id="reg-barangay-hidden">
            </div>

            <!-- Staff Fields -->
            <div class="staff-section <?= (($_POST['role'] ?? '') === 'staff') ? 'visible' : '' ?>" id="staffSection">
                <div class="staff-label"><i class="bi bi-person-badge"></i> Staff Details</div>

                <div class="form-row">
                    <div class="field">
                        <label>Staff ID <span class="req">*</span></label>
                        <input type="text" name="staff_id"
                               value="<?= htmlspecialchars($_POST['staff_id'] ?? '') ?>"
                               placeholder="e.g. STF-001">
                    </div>
                    <div class="field">
                        <label>Position <span class="req">*</span></label>
                        <input type="text" name="position"
                               value="<?= htmlspecialchars($_POST['position'] ?? '') ?>"
                               placeholder="e.g. Warehouse Manager">
                    </div>
                </div>

                <div class="field">
                    <label>Approval Code <span class="req">*</span></label>
                    <div class="input-wrap">
                        <input type="password" name="approval_code" id="ac"
                               placeholder="Provided by admin" autocomplete="off">
                        <button type="button" class="toggle-pw" data-target="ac" aria-label="Show code">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="field-hint">Provided by your administrator</div>
                </div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
                <i class="bi bi-person-plus-fill"></i>
                <span id="submitLabel">Create Account</span>
            </button>
        </form>

        <p class="login-link">
            Already have an account? <a href="login.php">Sign in here</a>
        </p>
    </div>
</div>

<script>
(function () {
    const btnCustomer  = document.getElementById('btnCustomer');
    const btnStaff     = document.getElementById('btnStaff');
    const roleInput    = document.getElementById('roleInput');
    const staffSection = document.getElementById('staffSection');
    const addrSection  = document.getElementById('addressSection');
    const submitLabel  = document.getElementById('submitLabel');

    function setRole(role) {
        roleInput.value = role;
        btnCustomer.classList.toggle('active', role === 'customer');
        btnStaff.classList.toggle('active',    role === 'staff');
        staffSection.classList.toggle('visible', role === 'staff');
        // Show address section only for customers
        addrSection.classList.toggle('hidden', role === 'staff');
        submitLabel.textContent = role === 'staff' ? 'Create Staff Account' : 'Create Account';
    }

    btnCustomer.addEventListener('click', () => setRole('customer'));
    btnStaff.addEventListener('click',    () => setRole('staff'));
    setRole(roleInput.value || 'customer');

    // ── Password show/hide ───────────────────────────────────────────────────
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

    // ── Password strength ─────────────────────────────────────────────────────
    const pwInput = document.getElementById('pw');
    const fill    = document.getElementById('strengthFill');
    const colors  = ['#ff5e5e', '#f0a030', '#f0c040', '#5effa3'];

    pwInput?.addEventListener('input', function () {
        const v = this.value;
        let score = 0;
        if (v.length >= 8)             score++;
        if (/[A-Z]/.test(v))           score++;
        if (/[0-9]/.test(v))           score++;
        if (/[@$!%*?&_\-#]/.test(v))   score++;
        fill.style.width      = (score * 25) + '%';
        fill.style.background = colors[score - 1] || 'transparent';
    });

    // ── PSGC Address Dropdowns (Registration) ────────────────────────────────
    const regProvince = document.getElementById('reg-province');
    const regCity     = document.getElementById('reg-city');
    const regBarangay = document.getElementById('reg-barangay');
    const manualWrap  = document.getElementById('reg-manual-wrap');

    // Saved values from PHP (to re-select after error reload)
    const savedProvince = <?= json_encode($addrProvince) ?>;
    const savedCity     = <?= json_encode($addrCity) ?>;
    const savedBarangay = <?= json_encode($addrBarangay) ?>;

    function makeOption(value, text, dataCode) {
        const opt       = document.createElement('option');
        opt.value       = value;
        opt.textContent = text;
        if (dataCode) opt.dataset.code = dataCode;
        return opt;
    }

    function clearSelect(sel, placeholder) {
        sel.innerHTML = '';
        sel.appendChild(makeOption('', placeholder));
    }

    // Load provinces
    fetch('../customer/ajax/get-provinces.php')
        .then(r => { if (!r.ok) throw new Error(); return r.json(); })
        .then(provinces => {
            clearSelect(regProvince, 'Select Province');
            provinces.forEach(p => regProvince.appendChild(makeOption(p.name, p.name, p.code)));

            // Re-select saved province after error reload
            if (savedProvince) {
                const opt = [...regProvince.options].find(o => o.value === savedProvince);
                if (opt) {
                    regProvince.value = savedProvince;
                    regProvince.dispatchEvent(new Event('change'));
                }
            }
        })
        .catch(() => {
            clearSelect(regProvince, 'Unavailable');
            regProvince.disabled = true;
            regCity.disabled     = true;
            regBarangay.disabled = true;
            manualWrap.style.display = 'block';
        });

    regProvince.addEventListener('change', function () {
        const code = this.options[this.selectedIndex]?.dataset.code;
        clearSelect(regCity, 'Loading...');
        clearSelect(regBarangay, 'Select city first');
        regCity.disabled     = true;
        regBarangay.disabled = true;

        if (!code) return;

        fetch('../customer/ajax/get-cities.php?province_code=' + encodeURIComponent(code))
            .then(r => { if (!r.ok) throw new Error(); return r.json(); })
            .then(cities => {
                clearSelect(regCity, 'Select City / Municipality');
                cities.forEach(c => regCity.appendChild(makeOption(c.name, c.name, c.code)));
                regCity.disabled = false;

                if (savedCity) {
                    const opt = [...regCity.options].find(o => o.value === savedCity);
                    if (opt) {
                        regCity.value = savedCity;
                        regCity.dispatchEvent(new Event('change'));
                    }
                }
            })
            .catch(() => { clearSelect(regCity, 'Failed to load cities'); });
    });

    regCity.addEventListener('change', function () {
        const code = this.options[this.selectedIndex]?.dataset.code;
        clearSelect(regBarangay, 'Loading...');
        regBarangay.disabled = true;

        if (!code) return;

        fetch('../customer/ajax/get-barangays.php?city_code=' + encodeURIComponent(code))
            .then(r => { if (!r.ok) throw new Error(); return r.json(); })
            .then(barangays => {
                clearSelect(regBarangay, 'Select Barangay');
                barangays.forEach(b => regBarangay.appendChild(makeOption(b.name, b.name)));
                regBarangay.disabled = false;

                if (savedBarangay) {
                    const opt = [...regBarangay.options].find(o => o.value === savedBarangay);
                    if (opt) regBarangay.value = savedBarangay;
                }
            })
            .catch(() => { clearSelect(regBarangay, 'Failed to load barangays'); });
    });
})();
</script>

</body>
</html>