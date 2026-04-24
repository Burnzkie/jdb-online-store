<?php
declare(strict_types=1);

class PaymentService
{
    private string $secretKey;
    private string $publicKey;
    private string $baseUrl = 'https://api.paymongo.com/v1';

    public function __construct()
    {
        $this->secretKey = $_ENV['PAYMONGO_SECRET_KEY'] ?? '';
        $this->publicKey = $_ENV['PAYMONGO_PUBLIC_KEY'] ?? '';
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
        string $customerName
    ): array {
        $payload = [
            'data' => [
                'attributes' => [
                    'amount'      => (int)round($amount * 100), // PayMongo uses centavos
                    'currency'    => 'PHP',
                    'description' => "JDB Parts Order $orderNumber",
                    'statement_descriptor' => 'JDB PARTS',
                    'payment_method_types' => ['gcash'],
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

        return ['success' => false, 'message' => 'Failed to create payment session.'];
    }

    /**
     * Create a Maya (PayMaya) payment link.
     */
    public function createMayaPayment(
        float  $amount,
        string $orderId,
        string $orderNumber,
        string $customerEmail,
        string $customerName
    ): array {
        // Same as GCash but different payment_method_types
        $payload = [
            'data' => [
                'attributes' => [
                    'amount'      => (int)round($amount * 100),
                    'currency'    => 'PHP',
                    'description' => "JDB Parts Order $orderNumber",
                    'payment_method_types' => ['paymaya'],
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

        return ['success' => false, 'message' => 'Failed to create Maya payment session.'];
    }

    /**
     * Verify a webhook event from PayMongo.
     * Call this from your webhook endpoint.
     */
    public function verifyWebhook(string $payload, string $signature): bool
    {
        $webhookSecret = $_ENV['PAYMONGO_WEBHOOK_SECRET'] ?? '';
        $computed = hash_hmac('sha256', $payload, $webhookSecret);
        return hash_equals($computed, $signature);
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
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
}