<?php

declare(strict_types=1);

class Product
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    // ─── Single-product reads ─────────────────────────────────────────────────

    /** Fetch one active product by ID or slug. */
    public function getProduct(?int $id = null, ?string $slug = null): ?array
    {
        if ($id === null && $slug === null) {
            return null;
        }
        [$col, $val] = $id !== null ? ['id', $id] : ['slug', $slug];

        $stmt = $this->pdo->prepare("
            SELECT p.*, c.name AS category_name
            FROM   products p
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE  p.{$col} = ? AND p.is_active = 1
            LIMIT  1
        ");
        $stmt->execute([$val]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ─── Collection reads ─────────────────────────────────────────────────────

    /**
     * All products (admin list, no is_active filter so admins see everything).
     * Supports search and pagination.
     */
    public function getAllProducts(
        int    $limit  = 50,
        int    $offset = 0,
        string $search = ''
    ): array {
        $params = [];
        $where  = '';

        if ($search !== '') {
            $where    = "WHERE (p.name LIKE :s OR p.sku LIKE :s OR c.name LIKE :s)";
            $params[':s'] = '%' . $search . '%';
        }

        $stmt = $this->pdo->prepare("
            SELECT p.*, c.name AS category_name
            FROM   products p
            LEFT JOIN categories c ON c.id = p.category_id
            {$where}
            ORDER BY p.id DESC
            LIMIT  :lim OFFSET :off
        ");

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Total product count – used for pagination. Supports same search filter. */
    public function getTotalProducts(string $search = ''): int
    {
        $where  = '';
        $params = [];

        if ($search !== '') {
            $where    = "WHERE (p.name LIKE :s OR p.sku LIKE :s OR c.name LIKE :s)";
            $params[':s'] = '%' . $search . '%';
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            {$where}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function getFeaturedProducts(int $limit = 8): array
    {
        $stmt = $this->pdo->prepare("
            SELECT p.*, c.name AS category_name
            FROM   products p
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE  p.is_featured = 1 AND p.is_active = 1 AND p.stock > 0
            ORDER BY p.created_at DESC
            LIMIT  :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProductsByCategory(int $categoryId, int $limit = 12): array
    {
        $stmt = $this->pdo->prepare("
            SELECT p.*, c.name AS category_name
            FROM   products p
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE  p.category_id = :cid AND p.is_active = 1 AND p.stock > 0
            ORDER BY p.created_at DESC
            LIMIT  :lim
        ");
        $stmt->bindValue(':cid', $categoryId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,      PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRelatedProducts(int $productId, int $categoryId, int $limit = 4): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, name, slug, short_description, price, sale_price, image, stock
            FROM   products
            WHERE  category_id = :cid
              AND  id != :pid
              AND  is_active = 1
              AND  stock > 0
            ORDER BY RAND()
            LIMIT  :lim
        ");
        $stmt->bindValue(':cid', $categoryId, PDO::PARAM_INT);
        $stmt->bindValue(':pid', $productId,  PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,      PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Products with stock at or below $threshold – useful for a low-stock alert. */
    public function getLowStockProducts(int $threshold = 5): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, name, sku, stock
            FROM   products
            WHERE  is_active = 1 AND stock <= :t
            ORDER BY stock ASC
        ");
        $stmt->bindValue(':t', $threshold, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Writes ───────────────────────────────────────────────────────────────

    public function create(array $data): bool
    {
        $slug = $this->resolveSlug($data);

        $stmt = $this->pdo->prepare("
            INSERT INTO products
                (name, slug, short_description, price, sale_price,
                 sku, stock, category_id, image, is_featured, is_active, created_at)
            VALUES
                (:name, :slug, :desc, :price, :sale,
                 :sku, :stock, :cat, :img, :feat, 1, NOW())
        ");
        return $stmt->execute([
            ':name'  => $data['name'],
            ':slug'  => $slug,
            ':desc'  => $data['short_description'] ?? '',
            ':price' => $data['price'],
            ':sale'  => $data['sale_price'] ?: null,
            ':sku'   => $data['sku'] ?? '',
            ':stock' => $data['stock'] ?? 0,
            ':cat'   => $data['category_id'] ?? 1,
            ':img'   => $data['image'] ?? null,
            ':feat'  => (int)(!empty($data['is_featured'])),
        ]);
    }

    public function update(int $id, array $data): bool
    {
        $slug = $this->resolveSlug($data);

        $stmt = $this->pdo->prepare("
            UPDATE products
            SET    name              = :name,
                   slug             = :slug,
                   short_description= :desc,
                   price            = :price,
                   sale_price       = :sale,
                   sku              = :sku,
                   stock            = :stock,
                   category_id      = :cat,
                   image            = :img,
                   is_featured      = :feat,
                   updated_at       = NOW()
            WHERE  id = :id
        ");
        return $stmt->execute([
            ':name'  => $data['name'],
            ':slug'  => $slug,
            ':desc'  => $data['short_description'] ?? '',
            ':price' => $data['price'],
            ':sale'  => $data['sale_price'] ?: null,
            ':sku'   => $data['sku'] ?? '',
            ':stock' => $data['stock'] ?? 0,
            ':cat'   => $data['category_id'] ?? 1,
            ':img'   => $data['image'] ?? null,
            ':feat'  => (int)(!empty($data['is_featured'])),
            ':id'    => $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM products WHERE id = ?");
        return $stmt->execute([$id]);
    }

    // ─── Utility ──────────────────────────────────────────────────────────────

    public function incrementViews(int $productId): bool
    {
        return $this->pdo->prepare(
            "UPDATE products SET views = views + 1 WHERE id = ?"
        )->execute([$productId]);
    }

    public function isInStock(int $productId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT stock FROM products WHERE id = ? AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && $row['stock'] > 0;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function resolveSlug(array $data): string
    {
        $base = !empty($data['slug']) ? $data['slug'] : ($data['name'] ?? 'product');
        return $this->generateSlug($base);
    }

    private function generateSlug(string $text): string
    {
        $text = trim(strtolower($text));
        $text = preg_replace('/[^a-z0-9-]+/', '-', $text);
        $text = preg_replace('/-+/', '-', $text);
        return trim($text, '-') ?: 'product';
    }
}