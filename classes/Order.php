<?php

declare(strict_types=1);

class Order
{
    private PDO $pdo;

    private const VALID_STATUSES = ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** All orders for the admin list, newest first. */
    public function getAllOrders(): array
    {
        $stmt = $this->pdo->query("
            SELECT o.id,
                   o.order_number,
                   o.total_amount                        AS total,
                   o.status,
                   o.payment_status,
                   o.created_at,
                   o.customer_name,
                   o.customer_email,
                   CONCAT(u.firstname, ' ', u.lastname)  AS user_fullname,
                   u.email                               AS user_email
            FROM   orders o
            LEFT JOIN users u ON u.id = o.user_id
            ORDER  BY o.created_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Single order with its line items. Returns null if not found. */
    public function getOrderById(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT o.*,
                   CONCAT(u.firstname, ' ', u.lastname) AS user_fullname,
                   u.email                              AS user_email
            FROM   orders o
            LEFT JOIN users u ON u.id = o.user_id
            WHERE  o.id = ?
            LIMIT  1
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return null;
        }

        $items = $this->pdo->prepare("
            SELECT oi.*,
                   p.name  AS product_name,
                   p.image AS product_image
            FROM   order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE  oi.order_id = ?
        ");
        $items->execute([$orderId]);
        $order['items'] = $items->fetchAll(PDO::FETCH_ASSOC);

        return $order;
    }

    /** Update the fulfilment status. Returns false for unknown status values. */
    public function updateStatus(int $orderId, string $status): bool
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            return false;
        }

        return $this->pdo->prepare(
            "UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?"
        )->execute([$status, $orderId]);
    }

    /** Update the payment status. */
    public function updatePaymentStatus(int $orderId, string $paymentStatus): bool
    {
        return $this->pdo->prepare(
            "UPDATE orders SET payment_status = ?, updated_at = NOW() WHERE id = ?"
        )->execute([$paymentStatus, $orderId]);
    }

    /** Return the list of allowed order statuses. */
    public static function validStatuses(): array
    {
        return self::VALID_STATUSES;
    }
}