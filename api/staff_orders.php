<?php
/**
 * Smart Eats - order board polling endpoint
 *
 * Returns a short fingerprint of the board rather than every order. If
 * the fingerprint matches what the browser already has, nothing has
 * changed and the response is a few dozen bytes. On a kitchen screen
 * left open all service that difference matters.
 *
 * Every figure here is scoped to the caller's own restaurant. Without
 * that, a quiet kitchen would hear the alert every time a busier
 * restaurant on the platform took an order, and the counts on screen
 * would belong to nobody in particular.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_once __DIR__ . '/../includes/restaurants.php';

if (!is_logged_in() || !has_role('staff', 'vendor', 'admin')) {
    json_error('Not authorised.', 403);
}

$scopeId = panel_restaurant_id();

$sql = "SELECT
          SUM(status = 'pending')                  AS pending,
          SUM(status IN ('confirmed','preparing')) AS preparing,
          SUM(status = 'ready')                    AS ready,
          SUM(status = 'out_for_delivery')         AS delivering
        FROM orders
        WHERE status IN ('pending','confirmed','preparing','ready','out_for_delivery')";
$params = [];

if ($scopeId) {
    $sql .= ' AND restaurant_id = ?';
    $params[] = $scopeId;
}

$counts = db_one($sql, $params);

// The most recent order still waiting to be accepted, used for the alert.
$newestSql = "SELECT o.order_number, o.customer_name, o.total, o.order_type,
                     r.name AS restaurant_name
              FROM orders o
              JOIN restaurants r ON r.id = o.restaurant_id
              WHERE o.status = 'pending'";
$newestParams = [];

if ($scopeId) {
    $newestSql .= ' AND o.restaurant_id = ?';
    $newestParams[] = $scopeId;
}

$newestSql .= ' ORDER BY o.created_at DESC LIMIT 1';

$newest = db_one($newestSql, $newestParams);

json_response([
    'ok'     => true,
    'stamp'  => board_stamp($scopeId),
    'scope'  => $scopeId,
    'counts' => [
        'pending'    => (int) ($counts['pending'] ?? 0),
        'preparing'  => (int) ($counts['preparing'] ?? 0),
        'ready'      => (int) ($counts['ready'] ?? 0),
        'delivering' => (int) ($counts['delivering'] ?? 0),
    ],
    'newest' => $newest ? [
        'order_number' => $newest['order_number'],
        'customer'     => $newest['customer_name'],
        'total'        => money($newest['total']),
        'type'         => $newest['order_type'] === 'pickup' ? 'collection' : 'delivery',
        // Only useful to an administrator watching every restaurant, but
        // harmless to include: the caller already has access to it.
        'restaurant'   => $newest['restaurant_name'],
    ] : null,
]);
