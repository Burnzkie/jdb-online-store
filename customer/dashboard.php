<?php
// customer/dashboard.php
declare(strict_types=1);
session_start();
require_once '../classes/Database.php';
require_once '../classes/CartService.php';
require_once '../classes/User.php';

if (!User::isLoggedIn() || $_SESSION['user_role'] !== 'customer') {
    header("Location: ../login.php");
    exit;
}

$pdo         = Database::getInstance()->getConnection();
$userObj     = new User($pdo);
$cartService = new CartService($pdo);

$user      = $userObj->getUserById((int)$_SESSION['user_id']);
$cartCount = $cartService->getItemCount();

$stmt = $pdo->prepare("
    SELECT id, order_number, total_amount, shipping_amount, status, payment_status, created_at
    FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5
");
$stmt->execute([$_SESSION['user_id']]);
$recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmtOrders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ?");
$stmtOrders->execute([$_SESSION['user_id']]);
$totalOrders = (int)$stmtOrders->fetchColumn();

$stmtSpent = $pdo->prepare("SELECT COALESCE(SUM(total_amount + shipping_amount), 0) FROM orders WHERE user_id = ? AND status = 'completed'");
$stmtSpent->execute([$_SESSION['user_id']]);
$totalSpent = (float)$stmtSpent->fetchColumn();

$stmtPending = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'pending'");
$stmtPending->execute([$_SESSION['user_id']]);
$pendingOrders = (int)$stmtPending->fetchColumn();

$firstName  = $user['firstname'] ?? 'Customer';
$fullName   = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
$initials   = strtoupper(substr($user['firstname'] ?? 'U', 0, 1) . substr($user['lastname'] ?? '', 0, 1));
$memberSince = isset($user['created_at']) ? date('M Y', strtotime($user['created_at'])) : '';

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — JDB Parts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Sora:wght@600;700&display=swap" rel="stylesheet">
     <?php $pageTitle = 'Page Title — JDB Parts'; require_once __DIR__ . '/partials/head.php'; ?>
    <style>
        :root {
            --primary:#1a56db; --primary-dark:#1344b4; --primary-soft:#eff4ff;
            --success:#0e9f6e; --success-soft:#f0fdf9;
            --warning:#d97706; --warning-soft:#fffbeb;
            --danger:#e02424;  --danger-soft:#fff5f5;
            --surface:#fff; --bg:#f4f6fb; --border:#e5e9f2;
            --text:#111827; --muted:#6b7280;
            --radius:14px; --radius-sm:9px;
        }
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

        /* NAV */
        .top-nav{background:var(--surface);border-bottom:1px solid var(--border);height:62px;display:flex;align-items:center;padding:0 24px;position:sticky;top:0;z-index:100;gap:12px;}
        .brand{font-family:'Sora',sans-serif;font-weight:700;font-size:1.1rem;color:var(--primary);text-decoration:none;display:flex;align-items:center;gap:9px;white-space:nowrap;}
        .brand-icon{width:34px;height:34px;background:var(--primary);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.85rem;}
        .nav-links{display:flex;gap:2px;margin-left:auto;}
        .nav-links a{display:flex;align-items:center;gap:6px;padding:7px 13px;border-radius:8px;font-size:.855rem;font-weight:500;color:var(--muted);text-decoration:none;transition:.15s;}
        .nav-links a:hover{background:var(--bg);color:var(--text);}
        .nav-links a.active{background:var(--primary-soft);color:var(--primary);}
        .cart-pill{position:relative;}
        .cart-pill .badge{position:absolute;top:-5px;right:-7px;background:var(--danger);color:#fff;border-radius:50%;width:17px;height:17px;font-size:.62rem;display:flex;align-items:center;justify-content:center;font-weight:700;}
        .nav-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#4f8ef7);display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;color:#fff;cursor:pointer;font-family:'Sora',sans-serif;}
        .dropdown-menu{border:1px solid var(--border);border-radius:var(--radius-sm);box-shadow:0 8px 24px rgba(0,0,0,.1);padding:6px;}
        .dropdown-item{border-radius:7px;font-size:.875rem;padding:8px 14px;color:var(--text);}
        .dropdown-item:hover{background:var(--bg);}
        .dropdown-divider{border-color:var(--border);margin:4px 0;}

        /* HERO */
        .hero{background:linear-gradient(135deg,#1344b4 0%,#1a56db 55%,#2563eb 100%);padding:36px 32px;border-radius:var(--radius);color:#fff;margin-bottom:24px;position:relative;overflow:hidden;}
        .hero::before{content:'';position:absolute;right:-60px;top:-60px;width:260px;height:260px;border-radius:50%;background:rgba(255,255,255,.07);}
        .hero::after{content:'';position:absolute;right:40px;bottom:-80px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.05);}
        .hero-greeting{font-size:.85rem;font-weight:500;opacity:.8;margin-bottom:4px;}
        .hero-name{font-family:'Sora',sans-serif;font-size:1.65rem;font-weight:700;margin-bottom:6px;}
        .hero-sub{opacity:.75;font-size:.875rem;}
        .hero-cta{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3);padding:9px 20px;border-radius:50px;font-size:.875rem;font-weight:600;text-decoration:none;margin-top:18px;backdrop-filter:blur(4px);transition:.2s;}
        .hero-cta:hover{background:rgba(255,255,255,.28);color:#fff;}

        /* STAT CARDS */
        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;}
        .stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px;display:flex;align-items:center;gap:16px;transition:.2s;text-decoration:none;color:inherit;}
        .stat-card:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,.08);color:inherit;}
        .stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;}
        .stat-icon.blue{background:var(--primary-soft);color:var(--primary);}
        .stat-icon.green{background:var(--success-soft);color:var(--success);}
        .stat-icon.amber{background:var(--warning-soft);color:var(--warning);}
        .stat-icon.red{background:var(--danger-soft);color:var(--danger);}
        .stat-value{font-family:'Sora',sans-serif;font-size:1.4rem;font-weight:700;line-height:1;margin-bottom:3px;}
        .stat-label{font-size:.78rem;color:var(--muted);font-weight:500;}

        /* SECTION */
        .section-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:20px;}
        .section-head{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
        .section-title{font-family:'Sora',sans-serif;font-size:.95rem;font-weight:700;}
        .btn-link-sm{font-size:.82rem;color:var(--primary);text-decoration:none;font-weight:600;}
        .btn-link-sm:hover{text-decoration:underline;}

        /* TABLE */
        .orders-table{width:100%;border-collapse:collapse;}
        .orders-table th{font-size:.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;padding:10px 18px;text-align:left;background:#fafbfd;border-bottom:1px solid var(--border);}
        .orders-table td{padding:14px 18px;font-size:.875rem;border-bottom:1px solid var(--border);vertical-align:middle;}
        .orders-table tr:last-child td{border-bottom:none;}
        .orders-table tr:hover td{background:#fafbfd;}
        .order-num{font-weight:600;color:var(--primary);}

        /* BADGES */
        .pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:50px;font-size:.75rem;font-weight:600;}
        .pill-completed{background:var(--success-soft);color:var(--success);}
        .pill-pending{background:var(--warning-soft);color:var(--warning);}
        .pill-cancelled{background:var(--danger-soft);color:var(--danger);}
        .pill-processing{background:var(--primary-soft);color:var(--primary);}
        .pill-default{background:#f3f4f6;color:var(--muted);}

        .btn-view{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:6px;font-size:.8rem;font-weight:600;color:var(--primary);border:1.5px solid var(--border);text-decoration:none;transition:.15s;background:var(--surface);}
        .btn-view:hover{border-color:var(--primary);background:var(--primary-soft);}

        /* EMPTY */
        .empty-state{padding:48px 24px;text-align:center;}
        .empty-state i{font-size:3rem;color:var(--border);margin-bottom:14px;}
        .empty-state p{color:var(--muted);font-size:.9rem;margin-bottom:16px;}

        /* QUICK ACTIONS */
        .quick-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;}
        .quick-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:18px;text-align:center;text-decoration:none;color:var(--text);transition:.2s;display:block;}
        .quick-card:hover{border-color:var(--primary);background:var(--primary-soft);color:var(--primary);transform:translateY(-2px);}
        .quick-card i{font-size:1.4rem;margin-bottom:8px;display:block;color:var(--primary);}
        .quick-card span{font-size:.82rem;font-weight:600;}

        /* TOAST */
        .toast-container{position:fixed;top:16px;right:16px;z-index:9999;}
        .jdb-toast{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 18px;display:flex;align-items:center;gap:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);min-width:280px;animation:toastIn .3s ease;font-size:.875rem;}
        @keyframes toastIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
        .toast-icon-success{color:var(--success);}
        .toast-icon-danger{color:var(--danger);}
        .toast-icon-warning{color:var(--warning);}
        .toast-close{margin-left:auto;background:none;border:none;cursor:pointer;color:var(--muted);font-size:.9rem;padding:0;}

        @media(max-width:900px){.stats-grid{grid-template-columns:repeat(2,1fr)}.quick-grid{grid-template-columns:repeat(3,1fr)}}
        @media(max-width:576px){.stats-grid{grid-template-columns:1fr 1fr}.hero{padding:24px 20px}.top-nav{padding:0 14px}.nav-links a span{display:none}.quick-grid{grid-template-columns:repeat(3,1fr)}}
    </style>
</head>
<body>

<nav class="top-nav">
    <a href="dashboard.php" class="brand">
        <span class="brand-icon"><i class="bi bi-shop-window"></i></span>
        JDB Parts
    </a>
    <div class="nav-links">
        <a href="dashboard.php" class="active"><i class="fas fa-home"></i> <span>Home</span></a>
        <a href="products.php"><i class="fas fa-store"></i> <span>Shop</span></a>
        <a href="orders.php"><i class="fas fa-box"></i> <span>Orders</span></a>
        <a href="cart.php" class="cart-pill">
            <i class="fas fa-shopping-cart"></i> <span>Cart</span>
            <?php if ($cartCount > 0): ?><span class="badge"><?= $cartCount ?></span><?php endif; ?>
        </a>
        <div class="dropdown ms-1">
            <div class="nav-avatar dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                <?= htmlspecialchars($initials) ?>
            </div>
            <ul class="dropdown-menu dropdown-menu-end">
                <li class="px-3 py-1">
                    <p class="fw-bold mb-0" style="font-size:.85rem"><?= htmlspecialchars($fullName) ?></p>
                    <p class="mb-0" style="font-size:.75rem;color:var(--muted)"><?= htmlspecialchars($user['email'] ?? '') ?></p>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="account.php"><i class="fas fa-user-cog me-2 text-muted"></i>Account Settings</a></li>
                <li><a class="dropdown-item" href="orders.php"><i class="fas fa-shopping-bag me-2 text-muted"></i>My Orders</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="../auth/logout.php" style="color:var(--danger)"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div style="max-width:1100px;margin:0 auto;padding:28px 20px 60px;">

    <!-- Hero -->
    <div class="hero">
        <p class="hero-greeting"><?= $greeting ?>, welcome back 👋</p>
        <h1 class="hero-name"><?= htmlspecialchars($firstName) ?>!</h1>
        <p class="hero-sub">Member since <?= $memberSince ?> &mdash; here's your account overview.</p>
        <a href="products.php" class="hero-cta"><i class="fas fa-shopping-bag"></i> Browse Products</a>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <a href="cart.php" class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-shopping-cart"></i></div>
            <div>
                <div class="stat-value"><?= $cartCount ?></div>
                <div class="stat-label">Items in Cart</div>
            </div>
        </a>
        <a href="orders.php" class="stat-card">
            <div class="stat-icon green"><i class="fas fa-box"></i></div>
            <div>
                <div class="stat-value"><?= $totalOrders ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
        </a>
        <a href="orders.php?status=pending" class="stat-card">
            <div class="stat-icon amber"><i class="fas fa-clock"></i></div>
            <div>
                <div class="stat-value"><?= $pendingOrders ?></div>
                <div class="stat-label">Pending Orders</div>
            </div>
        </a>
        <div class="stat-card" style="cursor:default;">
            <div class="stat-icon red"><i class="fas fa-peso-sign"></i></div>
            <div>
                <div class="stat-value" style="font-size:1.1rem;">&#8369;<?= number_format($totalSpent, 0) ?></div>
                <div class="stat-label">Total Spent</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-12">
            <!-- Recent Orders -->
            <div class="section-card">
                <div class="section-head">
                    <span class="section-title">Recent Orders</span>
                    <a href="orders.php" class="btn-link-sm">View all <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
                <?php if (empty($recentOrders)): ?>
                    <div class="empty-state">
                        <i class="fas fa-receipt"></i>
                        <p>No orders placed yet. Start shopping to see them here!</p>
                        <a href="products.php" style="display:inline-flex;align-items:center;gap:7px;padding:9px 20px;background:var(--primary);color:#fff;border-radius:var(--radius-sm);text-decoration:none;font-size:.875rem;font-weight:600;">
                            <i class="fas fa-shopping-bag"></i> Shop Now
                        </a>
                    </div>
                <?php else: ?>
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Date</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentOrders as $order):
                            $total = (float)$order['total_amount'] + (float)($order['shipping_amount'] ?? 0);
                            $status = strtolower($order['status']);
                            $pillClass = match($status) {
                                'completed'  => 'pill-completed',
                                'pending'    => 'pill-pending',
                                'cancelled'  => 'pill-cancelled',
                                'processing' => 'pill-processing',
                                default      => 'pill-default'
                            };
                            $dot = match($status) {
                                'completed' => '●', 'pending' => '●', 'cancelled' => '●', default => '●'
                            };
                        ?>
                            <tr>
                                <td><span class="order-num"><?= htmlspecialchars($order['order_number']) ?></span></td>
                                <td style="color:var(--muted)"><?= date('M d, Y', strtotime($order['created_at'])) ?></td>
                                <td style="font-weight:600">&#8369;<?= number_format($total, 2) ?></td>
                                <td><span class="pill <?= $pillClass ?>"><?= $dot ?> <?= ucfirst($status) ?></span></td>
                                <td><a href="order-details.php?id=<?= $order['id'] ?>" class="btn-view">View <i class="fas fa-chevron-right" style="font-size:.65rem"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php if (isset($_SESSION['flash'])): $f = $_SESSION['flash']; unset($_SESSION['flash']); ?>
<div class="toast-container">
    <div class="jdb-toast" id="jdbToast">
        <i class="fas <?= $f['type']==='success'?'fa-check-circle toast-icon-success':($f['type']==='danger'?'fa-times-circle toast-icon-danger':'fa-exclamation-circle toast-icon-warning') ?> fa-lg"></i>
        <span><?= htmlspecialchars($f['message']) ?></span>
        <button class="toast-close" onclick="document.getElementById('jdbToast').remove()"><i class="fas fa-times"></i></button>
    </div>
</div>
<script>setTimeout(()=>{const t=document.getElementById('jdbToast');if(t)t.style.opacity=0,setTimeout(()=>t.remove(),400)},4000)</script>
<?php endif; ?>
</body>
</html>