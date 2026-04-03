<?php
// customer/order-details.php
session_start();
require_once '../classes/Database.php';
require_once '../classes/User.php';

$isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';

if (!User::isLoggedIn() || !isset($_GET['id']) || (!$isAdmin && $_SESSION['user_role'] !== 'customer')) {
    header("Location: dashboard.php"); exit;
}

$pdo = Database::getInstance()->getConnection();
$orderId = (int)$_GET['id'];

if ($isAdmin) {
    $stmt = $pdo->prepare("SELECT o.*, CONCAT(u.firstname,' ',u.lastname) AS customer_fullname, u.email AS customer_email FROM orders o JOIN users u ON o.user_id=u.id WHERE o.id=? LIMIT 1");
    $stmt->execute([$orderId]);
} else {
    $stmt = $pdo->prepare("SELECT o.*, CONCAT(u.firstname,' ',u.lastname) AS customer_fullname, u.email AS customer_email FROM orders o JOIN users u ON o.user_id=u.id WHERE o.id=? AND o.user_id=? LIMIT 1");
    $stmt->execute([$orderId, $_SESSION['user_id']]);
}
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) { header("Location: " . ($isAdmin ? "../admin/orders.php" : "orders.php")); exit; }

$items = [];
try {
    $s = $pdo->prepare("SELECT oi.*, p.name, p.image FROM order_items oi LEFT JOIN products p ON oi.product_id=p.id WHERE oi.order_id=? ORDER BY oi.id");
    $s->execute([$orderId]);
    $items = $s->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

$subtotal   = (float)$order['total_amount'];
$shipping   = (float)($order['shipping_amount'] ?? 0);
$grandTotal = $subtotal + $shipping;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #<?= htmlspecialchars($order['order_number']) ?> — JDB Parts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Sora:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root{--primary:#1a56db;--primary-dark:#1344b4;--primary-soft:#eff4ff;--success:#0e9f6e;--success-soft:#f0fdf9;--danger:#e02424;--danger-soft:#fff5f5;--warning:#d97706;--warning-soft:#fffbeb;--surface:#fff;--bg:#f4f6fb;--border:#e5e9f2;--text:#111827;--muted:#6b7280;--radius:14px;--radius-sm:9px;}
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}
        .top-nav{background:var(--surface);border-bottom:1px solid var(--border);height:62px;display:flex;align-items:center;padding:0 24px;position:sticky;top:0;z-index:100;gap:12px;}
        .brand{font-family:'Sora',sans-serif;font-weight:700;font-size:1.1rem;color:var(--primary);text-decoration:none;display:flex;align-items:center;gap:9px;}
        .brand-icon{width:34px;height:34px;background:var(--primary);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.85rem;}
        .nav-links{display:flex;gap:2px;margin-left:auto;}
        .nav-links a{display:flex;align-items:center;gap:6px;padding:7px 13px;border-radius:8px;font-size:.855rem;font-weight:500;color:var(--muted);text-decoration:none;transition:.15s;}
        .nav-links a:hover{background:var(--bg);color:var(--text);} .nav-links a.active{background:var(--primary-soft);color:var(--primary);}

        .page-wrap{max-width:860px;margin:0 auto;padding:32px 20px 60px;}
        .breadcrumb-nav{display:flex;align-items:center;gap:8px;font-size:.8rem;color:var(--muted);margin-bottom:20px;}
        .breadcrumb-nav a{color:var(--muted);text-decoration:none;} .breadcrumb-nav a:hover{color:var(--primary);}
        .breadcrumb-nav .sep{color:var(--border);} .breadcrumb-nav .cur{color:var(--text);font-weight:600;}

        /* STATUS BANNER */
        .status-banner{border-radius:var(--radius);padding:20px 24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;}
        .status-banner.completed{background:var(--success-soft);border:1px solid #a7f3d0;}
        .status-banner.pending{background:var(--warning-soft);border:1px solid #fde68a;}
        .status-banner.cancelled{background:var(--danger-soft);border:1px solid #fca5a5;}
        .status-banner.processing{background:var(--primary-soft);border:1px solid #bfdbfe;}
        .status-banner.default{background:var(--bg);border:1px solid var(--border);}
        .banner-left{display:flex;align-items:center;gap:14px;}
        .banner-icon{width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;}
        .banner-icon.completed{background:var(--success);color:#fff;}
        .banner-icon.pending{background:var(--warning);color:#fff;}
        .banner-icon.cancelled{background:var(--danger);color:#fff;}
        .banner-icon.processing{background:var(--primary);color:#fff;}
        .banner-icon.default{background:var(--muted);color:#fff;}
        .banner-title{font-family:'Sora',sans-serif;font-size:.95rem;font-weight:700;}
        .banner-sub{font-size:.78rem;color:var(--muted);margin-top:2px;}

        /* CARDS */
        .section-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:16px;}
        .section-head{padding:14px 20px;border-bottom:1px solid var(--border);background:#fafbfd;}
        .section-head h2{font-family:'Sora',sans-serif;font-size:.9rem;font-weight:700;display:flex;align-items:center;gap:8px;}
        .section-head h2 i{color:var(--primary);}
        .section-body{padding:20px;}

        /* INFO GRID */
        .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
        .info-field p.label{font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;}
        .info-field p.value{font-size:.875rem;font-weight:600;margin:0;}
        .info-field p.value.mono{font-family:'Sora',sans-serif;}

        /* ITEMS TABLE */
        .items-table{width:100%;border-collapse:collapse;}
        .items-table th{font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;padding:10px 14px;text-align:left;border-bottom:1px solid var(--border);}
        .items-table td{padding:14px;border-bottom:1px solid var(--border);font-size:.875rem;vertical-align:middle;}
        .items-table tr:last-child td{border-bottom:none;}
        .product-cell{display:flex;align-items:center;gap:12px;}
        .product-thumb{width:52px;height:52px;border-radius:8px;object-fit:cover;border:1px solid var(--border);flex-shrink:0;}
        .product-thumb-placeholder{width:52px;height:52px;border-radius:8px;background:var(--bg);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--border);flex-shrink:0;}
        .product-name{font-weight:600;font-size:.875rem;}

        /* TOTALS */
        .totals-box{background:var(--bg);border-radius:var(--radius-sm);padding:16px 20px;}
        .total-row{display:flex;justify-content:space-between;font-size:.875rem;margin-bottom:8px;}
        .total-row.grand{font-weight:700;font-size:1.05rem;padding-top:12px;border-top:1px solid var(--border);margin-top:4px;}
        .total-row .label{color:var(--muted);}
        .total-row.grand .value{color:var(--primary);}

        /* ACTIONS */
        .action-bar{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;}
        .btn-back{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'Plus Jakarta Sans',sans-serif;font-size:.875rem;font-weight:600;color:var(--text);text-decoration:none;transition:.15s;}
        .btn-back:hover{border-color:var(--text);}
        .btn-shop{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;background:var(--primary);border:none;border-radius:var(--radius-sm);font-family:'Plus Jakarta Sans',sans-serif;font-size:.875rem;font-weight:600;color:#fff;text-decoration:none;transition:.15s;}
        .btn-shop:hover{background:var(--primary-dark);color:#fff;}

        @media(max-width:640px){.info-grid{grid-template-columns:1fr}.top-nav{padding:0 14px}.nav-links a span{display:none}}

        /* ── CANCEL / REFUND ACTIONS ── */
        .btn-cancel{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;background:var(--danger-soft);border:1.5px solid #fca5a5;border-radius:var(--radius-sm);font-size:.875rem;font-weight:600;color:var(--danger);cursor:pointer;transition:.15s;font-family:'Plus Jakarta Sans',sans-serif;}
        .btn-cancel:hover{background:var(--danger);color:#fff;border-color:var(--danger);}
        .btn-refund{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;background:var(--warning-soft);border:1.5px solid #fde68a;border-radius:var(--radius-sm);font-size:.875rem;font-weight:600;color:var(--warning);cursor:pointer;transition:.15s;font-family:'Plus Jakarta Sans',sans-serif;}
        .btn-refund:hover{background:var(--warning);color:#fff;border-color:var(--warning);}

        /* ── MODAL OVERLAY ── */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:999;align-items:center;justify-content:center;padding:20px;}
        .modal-overlay.open{display:flex;}
        .modal-box{background:var(--surface);border-radius:var(--radius);width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.2);overflow:hidden;animation:slideUp .25s ease;}
        @keyframes slideUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
        .modal-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
        .modal-header h3{font-family:'Sora',sans-serif;font-size:1rem;font-weight:700;display:flex;align-items:center;gap:9px;}
        .modal-close{background:none;border:none;cursor:pointer;color:var(--muted);font-size:1.1rem;padding:4px;line-height:1;}
        .modal-close:hover{color:var(--text);}
        .modal-body{padding:22px;}
        .modal-footer{padding:14px 22px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;background:#fafbfd;}

        /* ── FORM ELEMENTS IN MODAL ── */
        .form-label-sm{font-size:.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:6px;}
        .form-control-jdb{width:100%;border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:9px 12px;font-family:'Plus Jakarta Sans',sans-serif;font-size:.875rem;color:var(--text);background:var(--surface);transition:.15s;outline:none;}
        .form-control-jdb:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(26,86,219,.1);}
        .form-group{margin-bottom:16px;}
        .refund-method-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:6px;}
        .method-option{border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:10px 12px;cursor:pointer;display:flex;align-items:center;gap:8px;font-size:.82rem;font-weight:600;color:var(--muted);transition:.15s;}
        .method-option:hover{border-color:var(--primary);color:var(--primary);}
        .method-option input[type=radio]{accent-color:var(--primary);}
        .method-option input[type=radio]:checked + span{color:var(--primary);}
        .method-option:has(input:checked){border-color:var(--primary);background:var(--primary-soft);color:var(--primary);}

        /* ── REFUND STATUS BADGE ── */
        .refund-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:50px;font-size:.78rem;font-weight:700;background:var(--warning-soft);color:var(--warning);border:1px solid #fde68a;}
    </style>
</head>
<body>
<nav class="top-nav">
    <a href="dashboard.php" class="brand"><span class="brand-icon"><i class="fas fa-car-side"></i></span>JDB Parts</a>
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
        <a href="dashboard.php">Home</a><span class="sep">/</span>
        <a href="orders.php">My Orders</a><span class="sep">/</span>
        <span class="cur"><?= htmlspecialchars($order['order_number']) ?></span>
    </div>

    <?php
    $status = strtolower($order['status']);
    $bannerClass = in_array($status, ['completed','pending','cancelled','processing']) ? $status : 'default';
    $bannerIcon  = match($status) {
        'completed'  => 'fa-check',
        'pending'    => 'fa-clock',
        'cancelled'  => 'fa-times',
        'processing' => 'fa-sync',
        default      => 'fa-box'
    };
    $bannerMsg = match($status) {
        'completed'  => 'Your order has been delivered successfully.',
        'pending'    => 'Your order is awaiting processing.',
        'cancelled'  => 'This order has been cancelled.',
        'processing' => 'Your order is being processed.',
        default      => 'Order status updated.'
    };
    ?>
    <div class="status-banner <?= $bannerClass ?>">
        <div class="banner-left">
            <div class="banner-icon <?= $bannerClass ?>"><i class="fas <?= $bannerIcon ?>"></i></div>
            <div>
                <p class="banner-title">Order #<?= htmlspecialchars($order['order_number']) ?></p>
                <p class="banner-sub"><?= $bannerMsg ?> &mdash; Placed <?= date('F j, Y', strtotime($order['created_at'])) ?></p>
            </div>
        </div>
        <a href="orders.php" class="btn-back" style="padding:8px 16px;font-size:.8rem;">
            <i class="fas fa-arrow-left"></i> All Orders
        </a>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="section-card" style="height:100%">
                <div class="section-head"><h2><i class="fas fa-shipping-fast"></i> Shipping Details</h2></div>
                <div class="section-body">
                    <div class="info-grid">
                        <div class="info-field"><p class="label">Customer Name</p><p class="value"><?= htmlspecialchars($order['customer_fullname']) ?></p></div>
                        <div class="info-field"><p class="label">Email</p><p class="value"><?= htmlspecialchars($order['customer_email']) ?></p></div>
                        <?php if (!empty($order['customer_phone'])): ?>
                        <div class="info-field"><p class="label">Phone</p><p class="value"><?= htmlspecialchars($order['customer_phone']) ?></p></div>
                        <?php endif; ?>
                        <div class="info-field" style="grid-column:1/-1"><p class="label">Shipping Address</p><p class="value"><?= nl2br(htmlspecialchars($order['shipping_address'] ?? 'N/A')) ?></p></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="section-card" style="height:100%">
                <div class="section-head"><h2><i class="fas fa-credit-card"></i> Payment Info</h2></div>
                <div class="section-body">
                    <div class="info-grid">
                        <div class="info-field"><p class="label">Payment Method</p><p class="value"><?= ucfirst(htmlspecialchars($order['payment_method'] ?? 'COD')) ?></p></div>
                        <div class="info-field"><p class="label">Payment Status</p>
                            <?php $ps = strtolower($order['payment_status']); ?>
                            <p class="value"><span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:50px;font-size:.75rem;font-weight:700;background:<?= $ps==='paid'?'var(--success-soft)':'var(--warning-soft)' ?>;color:<?= $ps==='paid'?'var(--success)':'var(--warning)' ?>">● <?= ucfirst(str_replace('_',' ',$order['payment_status'])) ?></span></p>
                        </div>
                        <div class="info-field"><p class="label">Order Date</p><p class="value"><?= date('M j, Y', strtotime($order['created_at'])) ?></p></div>
                        <div class="info-field"><p class="label">Order Time</p><p class="value"><?= date('g:i A', strtotime($order['created_at'])) ?></p></div>
                    </div>
                    <?php if (!empty($order['notes'])): ?>
                    <div style="margin-top:14px;padding:10px 14px;background:var(--bg);border-radius:var(--radius-sm);">
                        <p style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">Order Notes</p>
                        <p style="font-size:.875rem;margin:0;"><?= nl2br(htmlspecialchars($order['notes'])) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Items -->
    <div class="section-card mt-3">
        <div class="section-head"><h2><i class="fas fa-list"></i> Order Items</h2></div>
        <?php if (empty($items)): ?>
            <div style="padding:32px;text-align:center;color:var(--muted)">No items found for this order.</div>
        <?php else: ?>
        <table class="items-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Unit Price</th>
                    <th>Qty</th>
                    <th style="text-align:right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item):
                $imgSrc = $item['image'] ?? '';
                if ($imgSrc && !preg_match('#^(https?://|/|\.\.)#', $imgSrc)) $imgSrc = '../' . $imgSrc;
            ?>
            <tr>
                <td>
                    <div class="product-cell">
                        <?php if ($imgSrc): ?>
                            <img src="<?= htmlspecialchars($imgSrc) ?>" class="product-thumb" alt="<?= htmlspecialchars($item['name']) ?>" onerror="this.style.display='none'">
                        <?php else: ?>
                            <div class="product-thumb-placeholder"><i class="fas fa-box"></i></div>
                        <?php endif; ?>
                        <span class="product-name"><?= htmlspecialchars($item['name']) ?></span>
                    </div>
                </td>
                <td>&#8369;<?= number_format((float)$item['price'], 2) ?></td>
                <td><?= (int)$item['quantity'] ?></td>
                <td style="text-align:right;font-weight:700">&#8369;<?= number_format((float)$item['price'] * (int)$item['quantity'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div style="padding:16px 20px;">
            <div class="totals-box">
                <div class="total-row"><span class="label">Subtotal</span><span>&#8369;<?= number_format($subtotal, 2) ?></span></div>
                <div class="total-row"><span class="label">Shipping Fee</span><span>&#8369;<?= number_format($shipping, 2) ?></span></div>
                <div class="total-row grand"><span class="label" style="color:var(--text)">Grand Total</span><span class="value">&#8369;<?= number_format($grandTotal, 2) ?></span></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="action-bar">
        <a href="orders.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Orders</a>
        <a href="products.php" class="btn-shop"><i class="fas fa-shopping-bag"></i> Continue Shopping</a>

        <?php
        $status        = strtolower($order['status']);
        $paymentStatus = strtolower($order['payment_status']);
        $isPaid        = $paymentStatus === 'paid';
        $isRefundPending = $paymentStatus === 'refund_pending';

        // Show Cancel button for pending or processing orders
        $canCancel = in_array($status, ['pending', 'processing'], true);

        // Show Refund button if: cancelled+paid, cancelled+refund_pending (show status), or completed+paid
        $canRequestRefund = ($status === 'cancelled' && $isPaid)
                         || ($status === 'completed' && $isPaid);

        if ($canCancel): ?>
        <button class="btn-cancel" onclick="openModal('cancelModal')">
            <i class="fas fa-times-circle"></i> Cancel Order
        </button>
        <?php endif; ?>

        <?php if ($isRefundPending): ?>
        <span class="refund-badge"><i class="fas fa-clock"></i> Refund Under Review</span>
        <?php elseif ($canRequestRefund): ?>
        <button class="btn-refund" onclick="openModal('refundModal')">
            <i class="fas fa-undo"></i> Request Refund
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($_SESSION['flash'])): $f = $_SESSION['flash']; unset($_SESSION['flash']); ?>
<div style="position:fixed;top:16px;right:16px;z-index:9999;max-width:360px">
    <?php
    $toastColor = match($f['type']) {
        'success' => 'var(--success)', 'danger' => 'var(--danger)',
        'warning' => 'var(--warning)', default   => 'var(--primary)'
    };
    $toastIcon = match($f['type']) {
        'success' => 'fa-check-circle', 'danger' => 'fa-exclamation-circle',
        'warning' => 'fa-exclamation-triangle', default => 'fa-info-circle'
    };
    ?>
    <div id="jdbToast" style="background:#fff;border:1px solid var(--border);border-radius:9px;padding:14px 18px;display:flex;align-items:flex-start;gap:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);font-size:.875rem;">
        <i class="fas <?= $toastIcon ?> fa-lg" style="color:<?= $toastColor ?>;margin-top:2px;flex-shrink:0"></i>
        <span style="flex:1"><?= strip_tags($f['message'], '<strong>') ?></span>
        <button onclick="document.getElementById('jdbToast').remove()" style="margin-left:4px;background:none;border:none;cursor:pointer;color:var(--muted);line-height:1"><i class="fas fa-times"></i></button>
    </div>
</div>
<script>setTimeout(()=>{const t=document.getElementById('jdbToast');if(t)t.remove()},7000)</script>
<?php endif; ?>

<!-- ══════════════════════════════════════════
     CANCEL ORDER MODAL
══════════════════════════════════════════ -->
<div class="modal-overlay" id="cancelModal" role="dialog" aria-modal="true" aria-labelledby="cancelModalTitle">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="cancelModalTitle"><i class="fas fa-times-circle" style="color:var(--danger)"></i> Cancel Order</h3>
            <button class="modal-close" onclick="closeModal('cancelModal')" aria-label="Close">&times;</button>
        </div>
        <form method="POST" action="cancel-order.php" id="cancelForm">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="order_id"   value="<?= $orderId ?>">

                <div style="background:var(--danger-soft);border:1px solid #fca5a5;border-radius:var(--radius-sm);padding:12px 16px;margin-bottom:18px;display:flex;gap:10px;align-items:flex-start;">
                    <i class="fas fa-exclamation-triangle" style="color:var(--danger);flex-shrink:0;margin-top:2px"></i>
                    <div style="font-size:.82rem;color:var(--danger)">
                        <strong>This action cannot be undone.</strong> Cancelling will restore item stock.
                        <?php if (in_array(strtolower($order['payment_method']), ['gcash', 'bank'], true)): ?>
                        If payment was already made, a refund request will be submitted automatically.
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label-sm" for="cancel_reason">Reason for cancellation <span style="color:var(--danger)">*</span></label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;">
                        <?php foreach ([
                            'Changed my mind',
                            'Found a better price',
                            'Ordered by mistake',
                            'Delivery time too long',
                            'Payment issue',
                            'Other',
                        ] as $preset): ?>
                        <label style="display:flex;align-items:center;gap:7px;padding:8px 10px;border:1.5px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;font-size:.8rem;font-weight:500;transition:.15s;">
                            <input type="radio" name="cancel_preset" value="<?= htmlspecialchars($preset) ?>" style="accent-color:var(--danger)" onchange="setPreset(this.value)">
                            <?= htmlspecialchars($preset) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <textarea id="cancel_reason" name="cancel_reason" class="form-control-jdb" rows="3"
                        placeholder="Tell us more (optional but helpful)…" maxlength="1000"
                        style="resize:vertical"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-back" onclick="closeModal('cancelModal')" style="border-radius:var(--radius-sm);padding:9px 18px;">
                    Keep Order
                </button>
                <button type="submit" class="btn-cancel" id="cancelSubmitBtn">
                    <i class="fas fa-times-circle"></i> Confirm Cancellation
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════
     REQUEST REFUND MODAL
══════════════════════════════════════════ -->
<div class="modal-overlay" id="refundModal" role="dialog" aria-modal="true" aria-labelledby="refundModalTitle">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="refundModalTitle"><i class="fas fa-undo" style="color:var(--warning)"></i> Request Refund</h3>
            <button class="modal-close" onclick="closeModal('refundModal')" aria-label="Close">&times;</button>
        </div>
        <form method="POST" action="request-refund.php" id="refundForm">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="order_id"   value="<?= $orderId ?>">

                <div style="background:var(--primary-soft);border:1px solid #bfdbfe;border-radius:var(--radius-sm);padding:12px 16px;margin-bottom:18px;font-size:.82rem;color:var(--primary);">
                    <i class="fas fa-info-circle" style="margin-right:6px"></i>
                    Refund of <strong>&#8369;<?= number_format($grandTotal, 2) ?></strong> will be reviewed within <strong>3–5 business days</strong> after submission.
                </div>

                <div class="form-group">
                    <label class="form-label-sm" for="refund_reason">Reason for refund <span style="color:var(--danger)">*</span></label>
                    <textarea id="refund_reason" name="refund_reason" class="form-control-jdb" rows="3"
                        placeholder="Describe why you're requesting a refund…" maxlength="1000" required
                        style="resize:vertical"></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label-sm">Preferred refund method <span style="color:var(--danger)">*</span></label>
                    <div class="refund-method-grid">
                        <label class="method-option">
                            <input type="radio" name="refund_method" value="gcash" required>
                            <span><i class="fas fa-mobile-alt"></i> GCash</span>
                        </label>
                        <label class="method-option">
                            <input type="radio" name="refund_method" value="bank_transfer">
                            <span><i class="fas fa-university"></i> Bank Transfer</span>
                        </label>
                        <label class="method-option">
                            <input type="radio" name="refund_method" value="store_credit">
                            <span><i class="fas fa-store"></i> Store Credit</span>
                        </label>
                        <label class="method-option">
                            <input type="radio" name="refund_method" value="original">
                            <span><i class="fas fa-redo"></i> Original Method</span>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label-sm" for="refund_details">Account / reference details</label>
                    <input type="text" id="refund_details" name="refund_details" class="form-control-jdb"
                        placeholder="e.g. GCash number, bank account number…" maxlength="500">
                    <p style="font-size:.72rem;color:var(--muted);margin-top:5px;">Provide your account details so we can process the refund.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-back" onclick="closeModal('refundModal')" style="border-radius:var(--radius-sm);padding:9px 18px;">
                    Cancel
                </button>
                <button type="submit" class="btn-refund" id="refundSubmitBtn">
                    <i class="fas fa-paper-plane"></i> Submit Request
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Modal helpers ────────────────────────────────
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}
// Close on backdrop click
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
});
// Close on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(el => closeModal(el.id));
});

// ── Preset reasons for cancel ────────────────────
function setPreset(val) {
    const ta = document.getElementById('cancel_reason');
    if (val !== 'Other') ta.value = val;
    else ta.focus();
}

// ── Prevent double-submit ────────────────────────
document.getElementById('cancelForm')?.addEventListener('submit', function(e) {
    const reason = document.getElementById('cancel_reason').value.trim();
    const preset = document.querySelector('input[name=cancel_preset]:checked');
    if (!reason && !preset) {
        e.preventDefault();
        alert('Please select or enter a cancellation reason.');
        return;
    }
    // Merge preset into textarea if textarea is empty
    if (!reason && preset) {
        document.getElementById('cancel_reason').value = preset.value;
    }
    const btn = document.getElementById('cancelSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';
});

document.getElementById('refundForm')?.addEventListener('submit', function() {
    const btn = document.getElementById('refundSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
});
</script>
</body>
</html>