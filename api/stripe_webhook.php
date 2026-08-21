<?php
/**
 * Smart Eats - Stripe webhook
 *
 * Stripe calls this endpoint when a payment settles. It exists because
 * the browser redirect is not reliable: a customer may close the tab
 * before returning, and the order would then stay unpaid even though
 * the money was taken.
 *
 * Every request is verified against the endpoint secret before anything
 * is written, so this URL cannot be used to mark orders paid.
 *
 * Local testing with the Stripe CLI:
 *   stripe listen --forward-to localhost/smarteats/api/stripe_webhook.php
 */

require_once __DIR__ . '/../includes/orders.php';
require_once __DIR__ . '/../includes/stripe.php';

// Sessions are irrelevant here and would only create junk session files.
$payload   = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!stripe_verify_webhook($payload, $signature, STRIPE_WEBHOOK_SECRET)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['received' => false, 'error' => 'Invalid signature']);
    exit;
}

$event = json_decode($payload, true) ?: [];
$type   = $event['type'] ?? '';
$intent = $event['data']['object'] ?? [];

$orderId = (int) ($intent['metadata']['order_id'] ?? 0);
$order   = $orderId ? order_by_id($orderId) : null;

if ($order) {
    switch ($type) {

        case 'payment_intent.succeeded':
            if ($order['payment_status'] !== 'paid') {
                mark_order_paid(
                    (int) $order['id'],
                    (string) $intent['id'],
                    ($intent['amount_received'] ?? $intent['amount'] ?? 0) / 100,
                    strtoupper($intent['currency'] ?? 'GBP')
                );
            }
            break;

        case 'payment_intent.payment_failed':
            if ($order['payment_status'] === 'unpaid') {
                mark_payment_failed(
                    (int) $order['id'],
                    (string) $intent['id'],
                    (float) $order['total'],
                    $intent['last_payment_error']['message'] ?? ''
                );
            }
            break;

        case 'charge.refunded':
            db_run(
                "UPDATE orders SET payment_status = 'refunded' WHERE id = ?",
                [$order['id']]
            );
            break;
    }
}

// Always acknowledge, otherwise Stripe retries an event we have already
// handled or deliberately ignored.
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['received' => true]);
