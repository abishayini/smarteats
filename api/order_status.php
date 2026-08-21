<?php
/**
 * Smart Eats - customer order status endpoint
 *
 * FR-06 live order tracking. Lets the tracking and confirmation pages
 * notice a status change without the customer refreshing.
 *
 * The same ownership rule as the tracking page applies: the order must
 * belong to this session or signed-in account.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';

$order = order_by_number(input('order'));

if (!$order || !can_view_order($order)) {
    json_error('Order not found.', 404);
}

json_response([
    'ok'             => true,
    'order_number'   => $order['order_number'],
    'status'         => $order['status'],
    'status_label'   => status_label($order['status']),
    'payment_status' => $order['payment_status'],
    'updated_at'     => $order['updated_at'],
]);
