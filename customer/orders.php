<?php
// customer/orders.php
session_start();
require_once '../classes/Database.php';
require_once '../classes/User.php';

if (!User::isLoggedIn() || $_SESSION['user_role'] !== 'customer') {
    header("Location: ../login.php");
    exit;
}


$pdo = Database::getInstance()->getConnection();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ?");
$totalStmt->execute([$_SESSION['user_id']]);
$totalOrders = (int)$totalStmt->fetchColumn();
$totalPages  = max(1, ceil($totalOrders / $perPage));

$stmt = $pdo->prepare("SELECT id, order_number, total_amount, shipping_amount, status, payment_status, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $_SESSION['user_id'], PDO::PARAM_INT);
$stmt->bindValue(2, $perPage,            PDO::PARAM_INT);
$stmt->bindValue(3, $offset,             PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'My Orders — JDB Parts'; require_once __DIR__ . '/partials/head.php'; ?>
    <style>
        :root{--primary:#1a56db;--primary-dark:#1344b4;--primary-soft:#eff4ff;--success:#0e9f6e;--success-soft:#f0fdf9;--danger:#e02424;--danger-soft:#fff5f5;--warning:#d97706;--warning-soft:#fffbeb;--surface:#fff;--bg:#f4f6fb;--border:#e5e9f2;--text:#111827;--muted:#6b7280;--radius:14px;--radius-sm:9px;}
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

        .top-nav{background:var(--surface);border-bottom:1px solid var(--border);height:62px;display:flex;align-items:center;padding:0 24px;position:sticky;top:0;z-index:100;gap:12px;}
        .brand{font-family:'Sora',sans-serif;font-weight:700;font-size:1.1rem;color:var(--primary);text-decoration:none;display:flex;align-items:center;gap:9px;}
        .brand-icon{width:34px;height:34px;background:var(--primary);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.85rem;}
        .nav-links{display:flex;gap:2px;margin-left:auto;}
        .nav-links a{display:flex;align-items:center;gap:6px;padding:7px 13px;border-radius:8px;font-size:.855rem;font-weight:500;color:var(--muted);text-decoration:none;transition:.15s;}
        .nav-links a:hover{background:var(--bg);color:var(--text);}
        .nav-links a.active{background:var(--primary-soft);color:var(--primary);}

        .page-wrap{max-width:960px;margin:0 auto;padding:32px 20px 60px;}
        .breadcrumb-nav{display:flex;align-items:center;gap:8px;font-size:.8rem;color:var(--muted);margin-bottom:20px;}
        .breadcrumb-nav a{color:var(--muted);text-decoration:none;} .breadcrumb-nav a:hover{color:var(--primary);}
        .breadcrumb-nav .sep{color:var(--border);} .breadcrumb-nav .cur{color:var(--text);font-weight:600;}
        .page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
        .page-header h1{font-family:'Sora',sans-serif;font-size:1.5rem;font-weight:700;}
        .page-header p{color:var(--muted);font-size:.875rem;margin-top:3px;}
        .total-chip{background:var(--primary-soft);color:var(--primary);font-size:.8rem;font-weight:700;padding:5px 14px;border-radius:50px;}

        /* FILTER BAR */
        .filter-bar{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
        .filter-btn{padding:6px 16px;border-radius:50px;font-size:.8rem;font-weight:600;border:1.5px solid var(--border);background:var(--surface);color:var(--muted);cursor:pointer;text-decoration:none;transition:.15s;}
        .filter-btn:hover,.filter-btn.active{border-color:var(--primary);background:var(--primary-soft);color:var(--primary);}

        /* ORDER CARDS */
        .order-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:14px;overflow:hidden;transition:.2s;}
        .order-card:hover{box-shadow:0 6px 20px rgba(0,0,0,.08);transform:translateY(-2px);}
        .order-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:#fafbfd;}
        .order-num{font-weight:700;font-size:.9rem;color:var(--primary);}
        .order-date{font-size:.78rem;color:var(--muted);margin-top:2px;}
        .order-body{padding:16px 20px;display:grid;grid-template-columns:1fr 1fr 1fr auto;align-items:center;gap:16px;}
        .ob-label{font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;}
        .ob-value{font-size:.9rem;font-weight:600;}
        .ob-value.amount{color:var(--text);font-size:1rem;}

        .pill{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:50px;font-size:.75rem;font-weight:700;}
        .pill-completed{background:var(--success-soft);color:var(--success);}
        .pill-pending{background:var(--warning-soft);color:var(--warning);}
        .pill-cancelled{background:var(--danger-soft);color:var(--danger);}
        .pill-processing{background:var(--primary-soft);color:var(--primary);}
        .pill-default{background:#f3f4f6;color:var(--muted);}
        .pill-shipped   { background: #eff6ff; color: #1d4ed8; }
        .pill-delivered { background: var(--success-soft); color: var(--success); }

        
        .btn-view{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:var(--radius-sm);font-size:.82rem;font-weight:600;color:var(--primary);border:1.5px solid var(--primary);text-decoration:none;background:var(--primary-soft);transition:.15s;white-space:nowrap;}
        .btn-view:hover{background:var(--primary);color:#fff;}

        /* EMPTY */
        .empty-state{text-align:center;padding:72px 24px;background:var(--surface);border-radius:var(--radius);border:1px solid var(--border);}
        .empty-icon{width:90px;height:90px;border-radius:50%;background:var(--bg);display:inline-flex;align-items:center;justify-content:center;font-size:2.2rem;color:var(--border);margin-bottom:18px;}
        .empty-state h3{font-family:'Sora',sans-serif;font-size:1.2rem;margin-bottom:8px;}
        .empty-state p{color:var(--muted);font-size:.875rem;margin-bottom:24px;}

        /* PAGINATION */
        .pagination-wrap{display:flex;justify-content:center;gap:6px;margin-top:28px;flex-wrap:wrap;}
        .page-btn{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.82rem;font-weight:600;border:1.5px solid var(--border);background:var(--surface);color:var(--text);text-decoration:none;transition:.15s;}
        .page-btn:hover,.page-btn.active{background:var(--primary);border-color:var(--primary);color:#fff;}

        @media(max-width:640px){.order-body{grid-template-columns:1fr 1fr}.top-nav{padding:0 14px}.nav-links a span{display:none}}
        @media(max-width:400px){.order-body{grid-template-columns:1fr}}
    </style>
</head>
<body>

<nav class="top-nav">
    <a href="dashboard.php" class="brand"><span class="brand-icon"><i class="bi bi-shop-window"></i></span>JDB Parts</a>
    <div class="nav-links">
        <a href="dashboard.php"><i class="fas fa-home"></i> <span>Home</span></a>
        <a href="products.php"><i class="fas fa-store"></i> <span>Shop</span></a>
        <a href="orders.php" class="active"><i class="fas fa-box"></i> <span>Orders</span></a>
        <a href="cart.php"><i class="fas fa-shopping-cart"></i> <span>Cart</span></a>
        <a href="account.php"><i class="fas fa-user"></i> <span>Account</span></a>
    </div>
</nav>

<div class="page-wrap">
    <div class="breadcrumb-nav">
        <a href="dashboard.php">Home</a><span class="sep">/</span><span class="cur">My Orders</span>
    </div>

    <div class="page-header">
        <div>
            <h1>My Orders</h1>
            <p>Track and manage all your purchases</p>
        </div>
        <span class="total-chip"><?= $totalOrders ?> order<?= $totalOrders !== 1 ? 's' : '' ?> total</span>
    </div>

    <?php if (empty($orders)): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-box-open"></i></div>
            <h3>No orders yet</h3>
            <p>When you place your first order it will appear here. Start shopping now!</p>
            <a href="products.php" style="display:inline-flex;align-items:center;gap:8px;padding:11px 28px;background:var(--primary);color:#fff;border-radius:var(--radius-sm);text-decoration:none;font-weight:600;font-size:.9rem;">
                <i class="fas fa-shopping-bag"></i> Start Shopping
            </a>
        </div>
    <?php else: ?>

        <?php foreach ($orders as $order):
            $status = strtolower($order['status']);
            
            $pillClass = match($status) {
                'completed'  => 'pill-completed',
                'delivered'  => 'pill-completed',   
                'pending'    => 'pill-pending',
                'cancelled'  => 'pill-cancelled',
                'processing' => 'pill-processing',
                'shipped'    => 'pill-shipped',    
                default      => 'pill-default'
            };
            $dot = '●';
            $grandTotal = (float)$order['total_amount'] + (float)($order['shipping_amount'] ?? 0);
        ?>
        <div class="order-card">
            <div class="order-head">
                <div>
                    <p class="order-num"><?= htmlspecialchars($order['order_number']) ?></p>
                    <p class="order-date"><i class="far fa-calendar me-1"></i><?= date('F j, Y \a\t g:i A', strtotime($order['created_at'])) ?></p>
                </div>
                <span class="pill <?= $pillClass ?>"><?= $dot ?> <?= ucfirst($status) ?></span>
            </div>
            <div class="order-body">
                <div>
                    <p class="ob-label">Order Total</p>
                    <p class="ob-value amount">&#8369;<?= number_format($grandTotal, 2) ?></p>
                </div>
                <div>
                    <p class="ob-label">Payment</p>
                    <p class="ob-value"><?= ucfirst(str_replace('_', ' ', htmlspecialchars($order['payment_status']))) ?></p>
                </div>
                <div>
                    <p class="ob-label">Status</p>
                    <span class="pill <?= $pillClass ?>"><?= $dot ?> <?= ucfirst($status) ?></span>
                </div>
                <a href="order-details.php?id=<?= $order['id'] ?>" class="btn-view">
                    View Details <i class="fas fa-arrow-right" style="font-size:.7rem"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
        <div class="pagination-wrap">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page-1 ?>" class="page-btn">&laquo;</a>
            <?php endif; ?>
            <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
                <a href="?page=<?= $i ?>" class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page+1 ?>" class="page-btn">&raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>