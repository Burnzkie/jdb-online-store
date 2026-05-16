<?php
declare(strict_types=1);

class PaymentService
{
    private string $secretKey;
    private string $publicKey;
    private string $baseUrl = 'https://api.paymongo.com/v1';

    public function __construct()
    {
        $this->secretKey = $_ENV['PAYMONGO_SECRET_KEY'] ?? getenv('PAYMONGO_SECRET_KEY') ?: '';
        $this->publicKey = $_ENV['PAYMONGO_PUBLIC_KEY'] ?? getenv('PAYMONGO_PUBLIC_KEY') ?: '';
    }

    /**
     * Returns true if API keys are configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->secretKey) && !empty($this->publicKey);
    }

    /**
     * Create a GCash payment link.
     * Returns the checkout URL to redirect the customer to.
     */
    public function createGcashPayment(
        float  $amount,
        string $orderId,
        string $orderNumber,
        string $customerEmail,
        string $customerName,
        array  $items = []
    ): array {
        $payload = [
            'data' => [
                'attributes' => [
                    'currency'    => 'PHP',
                    'description' => "JDB Parts Order $orderNumber",
                    'statement_descriptor' => 'JDB PARTS',
                    'payment_method_types' => ['gcash'],
                    'line_items'  => $this->buildLineItems($items, $amount),
                    'success_url' => $this->getBaseUrl() . "/customer/payment-success.php?order_id=$orderId",
                    'cancel_url'  => $this->getBaseUrl() . "/customer/payment-cancel.php?order_id=$orderId",
                    'metadata'    => [
                        'order_id'     => $orderId,
                        'order_number' => $orderNumber,
                        'customer'     => $customerName,
                    ],
                    'customer' => [
                        'email' => $customerEmail,
                        'name'  => $customerName,
                    ],
                ]
            ]
        ];

        $response = $this->makeRequest('POST', '/checkout_sessions', $payload);

        if (isset($response['data']['attributes']['checkout_url'])) {
            return [
                'success'      => true,
                'checkout_url' => $response['data']['attributes']['checkout_url'],
                'session_id'   => $response['data']['id'],
            ];
        }

        $detail = $response['errors'][0]['detail'] ?? $response['errors'][0]['code'] ?? 'Failed to create GCash payment session.';
        error_log("PaymentService GCash failed: $detail | Response: " . json_encode($response));
        return ['success' => false, 'message' => $detail];
    }

    /**
     * Create a Maya (PayMaya) payment link.
     */
    public function createMayaPayment(
        float  $amount,
        string $orderId,
        string $orderNumber,
        string $customerEmail,
        string $customerName,
        array  $items = []
    ): array {
        $payload = [
            'data' => [
                'attributes' => [
                    'currency'    => 'PHP',
                    'description' => "JDB Parts Order $orderNumber",
                    'payment_method_types' => ['paymaya'],
                    'line_items'  => $this->buildLineItems($items, $amount),
                    'success_url' => $this->getBaseUrl() . "/customer/payment-success.php?order_id=$orderId",
                    'cancel_url'  => $this->getBaseUrl() . "/customer/payment-cancel.php?order_id=$orderId",
                    'metadata'    => ['order_id' => $orderId, 'order_number' => $orderNumber],
                    'customer'    => ['email' => $customerEmail, 'name' => $customerName],
                ]
            ]
        ];

        $response = $this->makeRequest('POST', '/checkout_sessions', $payload);

        if (isset($response['data']['attributes']['checkout_url'])) {
            return [
                'success'      => true,
                'checkout_url' => $response['data']['attributes']['checkout_url'],
                'session_id'   => $response['data']['id'],
            ];
        }

        $detail = $response['errors'][0]['detail'] ?? $response['errors'][0]['code'] ?? 'Failed to create Maya payment session.';
        error_log("PaymentService Maya failed: $detail | Response: " . json_encode($response));
        return ['success' => false, 'message' => $detail];
    }

    /**
     * Build PayMongo line_items array from cart items.
     * Falls back to a single "Order Total" line item if no items provided.
     */
    private function buildLineItems(array $items, float $fallbackAmount): array
    {
        if (!empty($items)) {
            return array_map(fn($item) => [
                'name'     => $item['name'],
                'amount'   => (int)round((float)$item['price'] * 100),
                'currency' => 'PHP',
                'quantity' => (int)$item['quantity'],
            ], $items);
        }

        // Fallback: single line item with the grand total
        return [[
            'name'     => 'Order Total',
            'amount'   => (int)round($fallbackAmount * 100),
            'currency' => 'PHP',
            'quantity' => 1,
        ]];
    }

    /**
     * Verify a webhook event from PayMongo.
     * Call this from your webhook endpoint.
     */
    public function verifyWebhook(string $payload, string $signature): bool
    {
        $webhookSecret = $_ENV['PAYMONGO_WEBHOOK_SECRET']
            ?? getenv('PAYMONGO_WEBHOOK_SECRET')
            ?: '';

        if (empty($webhookSecret) || empty($signature)) {
            error_log('PaymentService::verifyWebhook — missing secret or signature.');
            return false;
        }

        // PayMongo signature header format:
        // t=<timestamp>,te=<test_sig>,li=<live_sig>
        // We must verify against the correct one (te = test mode, li = live mode).
        $parts = [];
        foreach (explode(',', $signature) as $part) {
            [$k, $v]    = explode('=', $part, 2) + ['', ''];
            $parts[$k]  = $v;
        }

        $timestamp = $parts['t'] ?? '';
        // Use live signature in production, test signature in test mode
        $sigToVerify = $parts['li'] ?? $parts['te'] ?? '';

        if (empty($timestamp) || empty($sigToVerify)) {
            error_log('PaymentService::verifyWebhook — could not parse signature header.');
            return false;
        }

        // PayMongo signs: "<timestamp>.<raw_payload>"
        $signedPayload = $timestamp . '.' . $payload;
        $computed      = hash_hmac('sha256', $signedPayload, $webhookSecret);

        return hash_equals($computed, $sigToVerify);
    }

    /**
     * Retrieve a checkout session to verify payment status.
     */
    public function getCheckoutSession(string $sessionId): ?array
    {
        return $this->makeRequest('GET', "/checkout_sessions/$sessionId");
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($this->secretKey . ':'),
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true) ?? [];

        if ($httpCode >= 400) {
            error_log("PayMongo API error ($httpCode): $response");
        }

        return $decoded;
    }

    private function getBaseUrl(): string
    {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'sql208.infinityfree.com');
    }
}