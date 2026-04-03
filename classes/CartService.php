<?php

declare(strict_types=1);


class CartService
{
    private PDO $pdo;
    private int $userId;

    public function __construct(PDO $pdo)
    {
        $this->pdo    = $pdo;
        $this->userId = (int)($_SESSION['user_id'] ?? 0);
    }

    // ─── Write operations ─────────────────────────────────────────────────────

    /**
     * Add (or increment) a product.
     * Returns ['success' => bool, 'message' => string].
     */
    public function addItem(int $productId, int $quantity = 1): array
    {
        if (!$this->assertLoggedIn($result)) {
            return $result;
        }

        $product = $this->fetchActiveProduct($productId);
        if (!$product) {
            return ['success' => false, 'message' => 'Product not found or unavailable.'];
        }

        if ($product['stock'] < 1) {
            return ['success' => false, 'message' => "'{$product['name']}' is out of stock."];
        }

        $existing = $this->getCartQty($productId);
        $desired  = $existing + $quantity;
        $capped   = min($desired, (int)$product['stock']);

        $this->upsertCartRow($productId, $capped);
        $this->syncSessionFromDb();

        $adjusted = $desired > $capped;
        return [
            'success' => true,
            'message' => $adjusted
                ? "Quantity adjusted to available stock ({$product['stock']}) for '{$product['name']}'."
                : "'{$product['name']}' added to cart.",
        ];
    }

    /**
     * Set an item to an exact quantity.
     * qty ≤ 0 removes the item.
     */
    public function updateItem(int $productId, int $quantity): array
    {
        if (!$this->assertLoggedIn($result)) {
            return $result;
        }

        if ($quantity <= 0) {
            return $this->removeItem($productId);
        }

        $product = $this->fetchActiveProduct($productId);
        if (!$product) {
            $this->deleteCartRow($productId);
            $this->syncSessionFromDb();
            return ['success' => false, 'message' => 'Product no longer available – removed from cart.'];
        }

        $capped   = min($quantity, (int)$product['stock']);
        $adjusted = $quantity > $capped;

        $this->upsertCartRow($productId, $capped);
        $this->syncSessionFromDb();

        return [
            'success' => !$adjusted,
            'message' => $adjusted
                ? "'{$product['name']}' quantity adjusted to stock ({$product['stock']})."
                : 'Cart updated.',
        ];
    }

    public function removeItem(int $productId): array
    {
        $this->deleteCartRow($productId);
        $this->syncSessionFromDb();
        return ['success' => true, 'message' => 'Item removed from cart.'];
    }

    public function clear(): void
    {
        if ($this->userId === 0) {
            return;
        }
        $this->pdo->prepare("DELETE FROM cart_items WHERE user_id = ?")->execute([$this->userId]);
        $_SESSION['cart'] = [];
    }

    // ─── Read operations ──────────────────────────────────────────────────────

    public function getItemCount(): int
    {
        if ($this->userId === 0) {
            return 0;
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM cart_items WHERE user_id = ?");
        $stmt->execute([$this->userId]);
        return (int)$stmt->fetchColumn();
    }

    public function isEmpty(): bool
    {
        return $this->getItemCount() === 0;
    }

    /** Full cart rows joined with live product data. */
    public function getCartItems(): array
    {
        if ($this->userId === 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT ci.product_id, ci.quantity,
                   p.name, p.price, p.sale_price, p.stock, p.image
            FROM   cart_items ci
            JOIN   products   p  ON p.id = ci.product_id
            WHERE  ci.user_id = ? AND p.is_active = 1
            ORDER BY ci.created_at ASC
        ");
        $stmt->execute([$this->userId]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qty   = (int)$row['quantity'];
            $price = (float)($row['sale_price'] ?: $row['price']);
            $items[] = [
                'product_id' => (int)$row['product_id'],
                'name'       => $row['name'],
                'price'      => $price,
                'stock'      => (int)$row['stock'],
                'image'      => $row['image'],
                'quantity'   => $qty,
                'line_total' => round($price * $qty, 2),
            ];
        }
        return $items;
    }

    public function calculateTotals(float $shipping = 0.0): array
    {
        $subtotal = array_sum(array_column($this->getCartItems(), 'line_total'));
        return [
            'subtotal'   => round($subtotal, 2),
            'shipping'   => $shipping,
            'total'      => round($subtotal + $shipping, 2),
            'item_count' => $this->getItemCount(),
        ];
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Sets $out to an error array if the user is not logged in.
     * Returns true if logged in, false otherwise.
     */
    private function assertLoggedIn(?array &$out): bool
    {
        if ($this->userId !== 0) {
            return true;
        }
        $out = ['success' => false, 'message' => 'Please log in to manage your cart.'];
        return false;
    }

    private function fetchActiveProduct(int $productId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, name, price, sale_price, stock, image
            FROM   products
            WHERE  id = ? AND is_active = 1
            LIMIT  1
        ");
        $stmt->execute([$productId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getCartQty(int $productId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT quantity FROM cart_items WHERE user_id = ? AND product_id = ? LIMIT 1"
        );
        $stmt->execute([$this->userId, $productId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function upsertCartRow(int $productId, int $quantity): void
    {
        $this->pdo->prepare("
            INSERT INTO cart_items (user_id, product_id, quantity, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = NOW()
        ")->execute([$this->userId, $productId, $quantity]);
    }

    private function deleteCartRow(int $productId): void
    {
        $this->pdo->prepare(
            "DELETE FROM cart_items WHERE user_id = ? AND product_id = ?"
        )->execute([$this->userId, $productId]);
    }

    /** Keep $_SESSION['cart'] in sync so legacy checkout code still works. */
    private function syncSessionFromDb(): void
    {
        if ($this->userId === 0) {
            return;
        }
        $stmt = $this->pdo->prepare(
            "SELECT product_id, quantity FROM cart_items WHERE user_id = ?"
        );
        $stmt->execute([$this->userId]);
        $_SESSION['cart'] = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $_SESSION['cart'][(int)$row['product_id']] = (int)$row['quantity'];
        }
    }
}