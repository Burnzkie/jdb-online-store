<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

class EmailService
{
    private PHPMailer $mail;

    public function __construct()
    {
        $this->mail = new PHPMailer(true);
        $this->mail->isSMTP();
        $this->mail->Host       = $_ENV['SMTP_HOST']     ?? 'smtp.gmail.com';
        $this->mail->SMTPAuth   = true;
        $this->mail->Username   = $_ENV['SMTP_USER']     ?? '';
        $this->mail->Password   = $_ENV['SMTP_PASS']     ?? '';
        $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $this->mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);
        $this->mail->setFrom($_ENV['SMTP_FROM'] ?? 'noreply@jdbparts.com', 'JDB Parts');
        $this->mail->isHTML(true);
    }

    /**
     * Send order confirmation email to customer.
     */
    public function sendOrderConfirmation(
        string $toEmail,
        string $toName,
        string $orderNumber,
        float  $grandTotal,
        array  $items,
        string $shippingAddress,
        string $paymentMethod
    ): bool {
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($toEmail, $toName);
            $this->mail->Subject = "Order Confirmed: $orderNumber — JDB Parts";

            $itemsHtml = '';
            foreach ($items as $item) {
                $lineTotal = number_format($item['price'] * $item['quantity'], 2);
                $itemsHtml .= "
                    <tr>
                        <td style='padding:8px;border-bottom:1px solid #eee'>{$item['name']}</td>
                        <td style='padding:8px;border-bottom:1px solid #eee;text-align:center'>{$item['quantity']}</td>
                        <td style='padding:8px;border-bottom:1px solid #eee;text-align:right'>₱$lineTotal</td>
                    </tr>";
            }

            $this->mail->Body = "
            <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
                <div style='background:#1a56db;color:white;padding:24px;text-align:center;border-radius:8px 8px 0 0'>
                    <h1 style='margin:0;font-size:24px'>Order Confirmed! ✅</h1>
                    <p style='margin:8px 0 0;opacity:.8'>$orderNumber</p>
                </div>
                <div style='background:#fff;padding:24px;border:1px solid #e5e9f2;'>
                    <p>Hi <strong>$toName</strong>,</p>
                    <p>Thank you for your order! Here's your summary:</p>

                    <table style='width:100%;border-collapse:collapse;margin:16px 0'>
                        <thead>
                            <tr style='background:#f8f9fa'>
                                <th style='padding:8px;text-align:left'>Product</th>
                                <th style='padding:8px;text-align:center'>Qty</th>
                                <th style='padding:8px;text-align:right'>Total</th>
                            </tr>
                        </thead>
                        <tbody>$itemsHtml</tbody>
                    </table>

                    <div style='background:#f8f9fa;padding:16px;border-radius:8px;margin:16px 0'>
                        <p style='margin:0'><strong>Grand Total:</strong> ₱" . number_format($grandTotal, 2) . "</p>
                        <p style='margin:4px 0'><strong>Payment:</strong> " . ucfirst($paymentMethod) . "</p>
                        <p style='margin:4px 0'><strong>Ship to:</strong> $shippingAddress</p>
                    </div>

                    <p>Estimated delivery: <strong>3–7 business days</strong></p>
                    <p style='color:#666;font-size:13px'>Questions? Reply to this email or contact us at support@jdbparts.com</p>
                </div>
                <div style='background:#f8f9fa;padding:16px;text-align:center;color:#999;font-size:12px;border-radius:0 0 8px 8px'>
                    © JDB Parts — Auto Parts Store
                </div>
            </div>";

            return $this->mail->send();
        } catch (Exception $e) {
            error_log("Email failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send shipping update email.
     */
    public function sendShippingUpdate(
        string $toEmail,
        string $toName,
        string $orderNumber,
        string $newStatus,
        string $trackingNumber = ''
    ): bool {
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($toEmail, $toName);
            $this->mail->Subject = "Order Update: $orderNumber — JDB Parts";

            $trackingHtml = $trackingNumber
                ? "<p><strong>Tracking Number:</strong> $trackingNumber</p>"
                : '';

            $this->mail->Body = "
            <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
                <div style='background:#1a56db;color:white;padding:24px;text-align:center'>
                    <h1>Order Status Update</h1>
                    <p>$orderNumber</p>
                </div>
                <div style='padding:24px;border:1px solid #e5e9f2'>
                    <p>Hi <strong>$toName</strong>,</p>
                    <p>Your order status has been updated to: <strong>" . ucfirst($newStatus) . "</strong></p>
                    $trackingHtml
                    <p><a href='https://jdbparts.com/customer/orders.php'
                          style='background:#1a56db;color:white;padding:12px 24px;border-radius:6px;text-decoration:none'>
                        Track Your Order
                    </a></p>
                </div>
            </div>";

            return $this->mail->send();
        } catch (Exception $e) {
            error_log("Email failed: " . $e->getMessage());
            return false;
        }
    }
}