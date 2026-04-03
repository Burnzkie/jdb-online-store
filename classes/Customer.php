<?php

declare(strict_types=1);

class Customer
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Retrieve all customers with their order count.
     * Optionally filter by name or email.
     */
    public function getAllCustomers(string $search = ''): array
    {
        $where  = "WHERE u.role = 'customer'";
        $params = [];

        if ($search !== '') {
            $where       .= " AND (u.firstname LIKE :s OR u.lastname LIKE :s OR u.email LIKE :s)";
            $params[':s'] = '%' . $search . '%';
        }

        $stmt = $this->pdo->prepare("
            SELECT u.id,
                   u.firstname,
                   u.lastname,
                   u.email,
                   u.phone,
                   u.created_at,
                   u.is_banned,
                   u.banned_at,
                   u.ban_reason,
                   COUNT(o.id) AS order_count
            FROM   users u
            LEFT JOIN orders o ON o.user_id = u.id
            {$where}
            GROUP  BY u.id
            ORDER  BY u.created_at DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single customer by ID.
     */
    public function getCustomerById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM users WHERE id = ? AND role = 'customer' LIMIT 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Ban a customer account.
     */
    public function ban(int $userId, ?string $reason = null): bool
    {
        return $this->pdo->prepare("
            UPDATE users
            SET    is_banned  = 1,
                   banned_at  = NOW(),
                   ban_reason = ?
            WHERE  id = ? AND role = 'customer'
        ")->execute([$reason, $userId]);
    }

    /**
     * Remove a ban from a customer account.
     */
    public function unban(int $userId): bool
    {
        return $this->pdo->prepare("
            UPDATE users
            SET    is_banned  = 0,
                   banned_at  = NULL,
                   ban_reason = NULL
            WHERE  id = ? AND role = 'customer'
        ")->execute([$userId]);
    }
}