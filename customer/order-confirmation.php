<?php
// customer/order-confirmation.php
session_start();
require_once '../classes/Database.php';
require_once '../classes/User.php';

if (!User::isLoggedIn() || $_SESSION['user_role'] !== 'customer') {
    header("Location: ../login.php"); exit;
}


$pdo = Database::getInstance()->getConnection();

if (!isset($_GET['order_id']) || !is_numeric($_GET['order_id'])) {
    $_SESSION['flash'] = ['type'=>'danger','message'=>'Invalid order.'];
    header("Location: cart.php"); exit;
}

$order_id = (int)$_GET['order_id'];
$stmt = $pdo->prepare("SELECT order_number, customer_name, total_amount, shipping_amount, payment_method, payment_status, status, shipping_address, notes, created_at FROM orders WHERE id=? AND user_id=?");
$stmt->execute([$order_id, $_SESSION['user_id'] ?? 0]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    $_SESSION['flash'] = ['type'=>'danger','message'=>'Order not found or access denied.'];
    header("Location: dashboard.php"); exit;
}

$stmt_items = $pdo->prepare("SELECT oi.quantity, oi.price, p.name, p.image FROM order_items oi JOIN products p ON oi.product_id=p.id WHERE oi.order_id=? ORDER BY oi.id");
$stmt_items->execute([$order_id]);
$items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

$subtotal   = (float)$order['total_amount'];
$shipping   = (float)($order['shipping_amount'] ?? 0);
$grandTotal = $subtotal + $shipping;

$est_from = date('M j', strtotime($order['created_at'].' +3 days'));
$est_to   = date('M j, Y', strtotime($order['created_at'].' +7 days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed — JDB Parts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Sora:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root{--primary:#1a56db;--primary-soft:#eff4ff;--success:#0e9f6e;--success-soft:#f0fdf9;--surface:#fff;--bg:#f4f6fb;--border:#e5e9f2;--text:#111827;--muted:#6b7280;--radius:14px;--radius-sm:9px;}
        *{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}
        .top-nav{background:var(--surface);border-bottom:1px solid var(--border);height:62px;display:flex;align-items:center;padding:0 24px;gap:12px;}
        .brand{font-family:'Sora',sans-serif;font-weight:700;font-size:1.1rem;color:var(--primary);text-decoration:none;display:flex;align-items:center;gap:9px;}
        .brand-icon{width:34px;height:34px;background:var(--primary);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.85rem;}

        .page-wrap{max-width:680px;margin:0 auto;padding:40px 20px 60px;}

        /* SUCCESS HERO */
        .confirm-hero{text-align:center;padding:36px 24px 28px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:20px;position:relative;overflow:hidden;}
        .confirm-hero::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--success),#34d399);}
        .success-ring{width:76px;height:76px;border-radius:50%;background:var(--success-soft);border:2px solid #a7f3d0;display:inline-flex;align-items:center;justify-content:center;font-size:2rem;color:var(--success);margin-bottom:16px;animation:popIn .5s cubic-bezier(.175,.885,.32,1.275);}
        @keyframes popIn{from{transform:scale(0);opacity:0}to{transform:scale(1);opacity:1}}
        .confirm-hero h1{font-family:'Sora',sans-serif;font-size:1.5rem;font-weight:700;margin-bottom:6px;}
        .confirm-hero p{color:var(--muted);font-size:.875rem;}
        .order-num-badge{display:inline-flex;align-items:center;gap:7px;background:var(--primary-soft);color:var(--primary);font-size:.85rem;font-weight:700;padding:7px 16px;border-radius:50px;margin-top:14px;font-family:'Sora',sans-serif;}

        /* TRACKER */
        .tracker{display:flex;align-items:flex-start;justify-content:center;gap:0;padding:24px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:20px;overflow-x:auto;}
        .track-step{display:flex;flex-direction:column;align-items:center;text-align:center;flex:1;min-width:80px;position:relative;}
        .track-step:not(:last-child)::after{content:'';position:absolute;top:20px;left:calc(50% + 20px);right:calc(-50% + 20px);height:2px;background:var(--border);}
        .track-step.done:not(:last-child)::after{background:var(--success);}
        .track-dot{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.85rem;margin-bottom:8px;position:relative;z-index:1;}
        .track-dot.done{background:var(--success);color:#fff;}
        .track-dot.active{background:var(--primary);color:#fff;box-shadow:0 0 0 4px rgba(26,86,219,.15);}
        .track-dot.idle{background:var(--bg);border:2px solid var(--border);color:var(--muted);}
        .track-label{font-size:.72rem;font-weight:600;color:var(--muted);}
        .track-step.done .track-label{color:var(--success);}
        .track-step.active .track-label{color:var(--primary);}

        /* SECTION CARDS */
        .section-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:16px;}
        .section-head{padding:14px 20px;border-bottom:1px solid var(--border);background:#fafbfd;}
        .section-head h2{font-family:'Sora',sans-serif;font-size:.9rem;font-weight:700;display:flex;align-items:center;gap:8px;}
        .section-head h2 i{color:var(--primary);}
        .section-body{padding:20px;}

        /* ITEMS */
        .order-item{display:flex;align-items:center;gap:14px;padding-bottom:14px;margin-bottom:14px;border-bottom:1px solid var(--border);}
        .order-item:last-child{border-bottom:none;padding-bottom:0;margin-bottom:0;}
        .order-item-img{width:56px;height:56px;border-radius:8px;object-fit:cover;border:1px solid var(--border);flex-shrink:0;}
        .order-item-placeholder{width:56px;height:56px;border-radius:8px;background:var(--bg);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--border);flex-shrink:0;}
        .order-item-info{flex:1;min-width:0;}
        .order-item-name{font-weight:600;font-size:.875rem;}
        .order-item-qty{font-size:.78rem;color:var(--muted);margin-top:2px;}
        .order-item-price{font-weight:700;font-size:.9rem;flex-shrink:0;}

        /* TOTALS */
        .totals-box{background:var(--bg);border-radius:var(--radius-sm);padding:16px;}
        .total-row{display:flex;justify-content:space-between;font-size:.875rem;margin-bottom:8px;}
        .total-row .label{color:var(--muted);}
        .total-row.grand{font-weight:700;font-size:1.05rem;padding-top:12px;border-top:1px solid var(--border);margin-top:4px;}
        .total-row.grand .value{color:var(--primary);}

        /* CTA */
        .cta-wrap{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;}
        .btn-primary-solid{display:inline-flex;align-items:center;gap:8px;padding:11px 24px;background:var(--primary);color:#fff;border-radius:var(--radius-sm);text-decoration:none;font-weight:700;font-size:.9rem;transition:.2s;}
        .btn-primary-solid:hover{background:#1344b4;color:#fff;}
        .btn-outline{display:inline-flex;align-items:center;gap:8px;padding:10px 22px;background:var(--surface);border:1.5px solid var(--border);color:var(--text);border-radius:var(--radius-sm);text-decoration:none;font-weight:600;font-size:.875rem;transition:.15s;}
        .btn-outline:hover{border-color:var(--text);}

        @media(max-width:480px){.tracker{gap:0}.track-step{min-width:70px}}
    </style>
</head>
<body>
<nav class="top-nav">
    <a href="dashboard.php" class="brand"><span class="brand-icon"><i class="fas fa-car-side"></i></span>JDB Parts</a>
</nav>

<div class="page-wrap">

    <!-- Confirm Hero -->
    <div class="confirm-hero">
        <div class="success-ring"><i class="fas fa-check"></i></div>
        <h1>Order Confirmed!</h1>
        <p>Thank you for your purchase. We've received your order and will process it shortly.</p>
        <div class="order-num-badge"><i class="fas fa-receipt"></i> <?= htmlspecialchars($order['order_number']) ?></div>
        <p style="margin-top:10px;font-size:.78rem;color:var(--muted)">Placed on <?= date('F j, Y \a\t g:i A', strtotime($order['created_at'])) ?></p>
    </div>

                    <!-- Order Tracker -->
                <div class="tracker">
                    <?php
                    $status = $order['status'];
                    $steps = [
                        [
                            'icon'  => 'fa-check-circle',
                            'label' => 'Order Placed',
                            'state' => 'done',
                        ],
                        [
                            'icon'  => 'fa-cog',
                            'label' => 'Processing',
                            'state' => match($status) {
                                'pending', 'processing' => 'active',
                                default                 => 'done',
                            },
                        ],
                        [
                            'icon'  => 'fa-truck',
                            'label' => 'Shipped',
                            'state' => match($status) {
                                'pending', 'processing' => 'idle',
                                'shipped'               => 'active',
                                default                 => 'done',
                            },
                        ],
                        [
                            'icon'  => 'fa-home',
                            'label' => 'Delivered',
                            'state' => in_array($status, ['delivered', 'completed']) ? 'done' : 'idle',
                        ],
                    ];

                    // ← THIS FOREACH WAS MISSING — add it
                    foreach ($steps as $step): ?>
                    <div class="track-step <?= $step['state'] ?>">
                        <div class="track-dot <?= $step['state'] ?>">
                            <i class="fas <?= $step['state'] === 'done' ? 'fa-check' : $step['icon'] ?>"></i>
                        </div>
                        <span class="track-label"><?= $step['label'] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>

    <!-- Delivery Info -->
    <div style="background:var(--primary-soft);border:1px solid #bfdbfe;border-radius:var(--radius-sm);padding:14px 18px;display:flex;align-items:center;gap:12px;margin-bottom:20px;">
        <i class="fas fa-truck" style="color:var(--primary);font-size:1.2rem;flex-shrink:0"></i>
        <div>
            <p style="font-weight:700;font-size:.875rem;margin:0">Estimated Delivery</p>
            <p style="font-size:.78rem;color:var(--muted);margin:2px 0 0"><?= $est_from ?> – <?= $est_to ?></p>
        </div>
    </div>

    <!-- Items -->
    <div class="section-card">
        <div class="section-head"><h2><i class="fas fa-list"></i> Items Ordered</h2></div>
        <div class="section-body">
            <?php foreach ($items as $item):
                $imgSrc = $item['image'] ?? '';
                if ($imgSrc && !preg_match('#^(https?://|/|\.\.)#', $imgSrc)) $imgSrc = '../' . $imgSrc;
            ?>
            <div class="order-item">
                <?php if ($imgSrc): ?>
                    <img src="<?= htmlspecialchars($imgSrc) ?>" class="order-item-img" alt="<?= htmlspecialchars($item['name']) ?>" onerror="this.style.display='none'">
                <?php else: ?>
                    <div class="order-item-placeholder"><i class="fas fa-box"></i></div>
                <?php endif; ?>
                <div class="order-item-info">
                    <p class="order-item-name"><?= htmlspecialchars($item['name']) ?></p>
                    <p class="order-item-qty">Qty: <?= (int)$item['quantity'] ?> × &#8369;<?= number_format((float)$item['price'],2) ?></p>
                </div>
                <p class="order-item-price">&#8369;<?= number_format((float)$item['price']*(int)$item['quantity'],2) ?></p>
            </div>
            <?php endforeach; ?>

            <div class="totals-box">
                <div class="total-row"><span class="label">Subtotal</span><span>&#8369;<?= number_format($subtotal,2) ?></span></div>
                <div class="total-row"><span class="label">Shipping Fee</span><span>&#8369;<?= number_format($shipping,2) ?></span></div>
                <div class="total-row grand"><span class="label" style="color:var(--text)">Total Paid</span><span class="value">&#8369;<?= number_format($grandTotal,2) ?></span></div>
            </div>
        </div>
    </div>

    <!-- Payment & Address -->
    <div class="section-card">
        <div class="section-head"><h2><i class="fas fa-info-circle"></i> Order Details</h2></div>
        <div class="section-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;font-size:.875rem;">
                <div>
                    <p style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;">Payment Method</p>
                    <p style="font-weight:600;"><?= ucfirst(htmlspecialchars($order['payment_method'])) ?></p>
                </div>
                <div>
                    <p style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;">Payment Status</p>
                    <p style="font-weight:600;"><?= ucfirst(str_replace('_',' ',$order['payment_status'])) ?></p>
                </div>
                <div style="grid-column:1/-1">
                    <p style="font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;">Shipping To</p>
                    <p style="font-weight:600;"><?= nl2br(htmlspecialchars($order['shipping_address'] ?? '')) ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="cta-wrap">
        <a href="orders.php" class="btn-outline"><i class="fas fa-list"></i> My Orders</a>
        <a href="products.php" class="btn-primary-solid"><i class="fas fa-shopping-bag"></i> Continue Shopping</a>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php if (isset($_SESSION['flash'])): $f = $_SESSION['flash']; unset($_SESSION['flash']); ?>
<div style="position:fixed;top:16px;right:16px;z-index:9999">
    <div id="jdbToast" style="background:#fff;border:1px solid #e5e9f2;border-radius:9px;padding:14px 18px;display:flex;align-items:center;gap:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);min-width:280px;font-size:.875rem;">
        <i class="fas fa-check-circle fa-lg" style="color:var(--success)"></i>
        <span><?= strip_tags(htmlspecialchars_decode(htmlspecialchars($f['message'])),'<strong>') ?></span>
        <button onclick="document.getElementById('jdbToast').remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;color:var(--muted)"><i class="fas fa-times"></i></button>
    </div>
</div>
<script>setTimeout(()=>{const t=document.getElementById('jdbToast');if(t)t.remove()},6000)</script>
<?php endif; ?>
</body>
</html>