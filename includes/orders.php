<?php
/**
 * Smart Eats - orders
 *
 * Creating an order from the basket, reading it back, deciding who may
 * see it, and moving it through the kitchen.
 *
 * From Phase 11 every order carries a restaurant_id. That column is the
 * boundary the whole platform rests on: the live board, the polling
 * endpoint, the order detail screen and the history report all filter on
 * it, so one restaurant's staff never see another's orders.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/restaurants.php';

/* ------------------------------------------------------------------ */
/* Creation                                                            */
/* ------------------------------------------------------------------ */

/**
 * Turn the current basket into an order.
 *
 * Prices are read from the database rather than the request, so a
 * tampered basket cannot change what is charged. The whole write runs in
 * a transaction: a half-written order with no line items would be worse
 * than no order at all.
 *
 * @param array $details name, email, phone, address, notes, order_type,
 *                       payment_method
 */
function create_order_from_cart(array $details): array
{
    $restaurant = cart_restaurant();
    $items      = cart_items();

    if (!$restaurant || !$items) {
        throw new RuntimeException('The basket is empty or has no restaurant.');
    }

    if (!restaurant_is_open($restaurant)) {
        throw new RuntimeException('That restaurant is not taking orders.');
    }

    $orderType = in_array($details['order_type'] ?? '', ['delivery', 'pickup'], true)
        ? $details['order_type']
        : 'delivery';

    $totals = cart_totals($orderType);
    $user   = current_user();
    $pdo    = db();

    $pdo->beginTransaction();

    try {
        $orderId = db_insert(
            'INSERT INTO orders
               (order_number, restaurant_id, user_id, customer_name, customer_email,
                customer_phone, order_type, delivery_address, subtotal, tax,
                delivery_fee, total, payment_method, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                generate_order_number(),
                $restaurant['id'],
                $user['id'] ?? null,
                $details['name'],
                ($details['email'] ?? '') !== '' ? $details['email'] : null,
                $details['phone'],
                $orderType,
                $orderType === 'delivery' ? $details['address'] : null,
                $totals['subtotal'],
                $totals['tax'],
                $totals['delivery_fee'],
                $totals['total'],
                in_array($details['payment_method'] ?? '', ['card', 'cash'], true)
                    ? $details['payment_method']
                    : 'card',
                ($details['notes'] ?? '') !== '' ? $details['notes'] : null,
            ]
        );

        foreach ($items as $item) {
            db_run(
                'INSERT INTO order_items
                   (order_id, menu_item_id, item_name, unit_price, quantity, line_total)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $orderId,
                    $item['id'],
                    $item['name'],       // snapshot, so later menu edits
                    $item['price'],      // cannot rewrite this order
                    $item['quantity'],
                    $item['line_total'],
                ]
            );
        }

        db_run(
            'INSERT INTO order_status_history (order_id, status, note)
             VALUES (?, ?, ?)',
            [$orderId, 'pending', 'Order placed by customer']
        );

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    cart_clear();
    remember_order($orderId);

    return order_by_id($orderId);
}

/* ------------------------------------------------------------------ */
/* Lookup                                                              */
/* ------------------------------------------------------------------ */

function order_by_id(int $id): ?array
{
    return db_one('SELECT * FROM orders WHERE id = ? LIMIT 1', [$id]);
}

function order_by_number(string $orderNumber): ?array
{
    return db_one('SELECT * FROM orders WHERE order_number = ? LIMIT 1', [$orderNumber]);
}

/** The order plus the restaurant that will cook it, for customer views. */
function order_with_restaurant(int $id): ?array
{
    return db_one(
        'SELECT o.*, r.name AS restaurant_name, r.slug AS restaurant_slug,
                r.phone AS restaurant_phone, r.address AS restaurant_address,
                r.opening_hours AS restaurant_hours
         FROM orders o
         JOIN restaurants r ON r.id = o.restaurant_id
         WHERE o.id = ? LIMIT 1',
        [$id]
    );
}

function order_lines(int $orderId): array
{
    return db_all(
        'SELECT menu_item_id, item_name, unit_price, quantity, line_total
         FROM order_items WHERE order_id = ? ORDER BY id',
        [$orderId]
    );
}

function order_history(int $orderId): array
{
    return db_all(
        'SELECT status, note, changed_at
         FROM order_status_history WHERE order_id = ? ORDER BY changed_at, id',
        [$orderId]
    );
}

/* ------------------------------------------------------------------ */
/* Ownership                                                           */
/* ------------------------------------------------------------------ */

/**
 * Guests have no account, so the orders they place in this session are
 * recorded against the session itself. Without this a guest could not
 * see their own confirmation page.
 */
function remember_order(int $orderId): void
{
    $_SESSION['my_orders'] = $_SESSION['my_orders'] ?? [];
    if (!in_array($orderId, $_SESSION['my_orders'], true)) {
        $_SESSION['my_orders'][] = $orderId;
    }
}

/**
 * Whether the current visitor is allowed to view an order.
 *
 * Staff and vendors may view orders placed with their own restaurant and
 * no others. Before Phase 11 any staff account could open any order,
 * which on a shared platform would expose one business's customers to a
 * competitor.
 */
function can_view_order(array $order): bool
{
    $user = current_user();

    if ($user && $user['role'] === 'admin') {
        return true;
    }

    if ($user && in_array($user['role'], ['staff', 'vendor'], true)) {
        return (int) $user['restaurant_id'] === (int) $order['restaurant_id'];
    }

    if (is_logged_in() && (int) $order['user_id'] === (int) $_SESSION['user_id']) {
        return true;
    }

    return in_array((int) $order['id'], $_SESSION['my_orders'] ?? [], true);
}

/** Whether a panel user may change this order. Same rule, named clearly. */
function can_manage_order(array $order): bool
{
    return has_role('admin', 'vendor', 'staff')
        && can_manage_restaurant((int) $order['restaurant_id']);
}

/* ------------------------------------------------------------------ */
/* Status changes                                                      */
/* ------------------------------------------------------------------ */

/**
 * Move an order to a new status and log the change. Every transition is
 * recorded with a timestamp, which is what makes order processing time
 * measurable rather than estimated.
 */
function set_order_status(int $orderId, string $status, ?int $changedBy = null, ?string $note = null): bool
{
    global $ORDER_STATUSES;

    if (!isset($ORDER_STATUSES[$status])) {
        return false;
    }

    $current = db_value('SELECT status FROM orders WHERE id = ?', [$orderId]);
    if ($current === null || $current === $status) {
        return false;
    }

    db_run('UPDATE orders SET status = ? WHERE id = ?', [$status, $orderId]);
    db_run(
        'INSERT INTO order_status_history (order_id, status, changed_by, note)
         VALUES (?, ?, ?, ?)',
        [$orderId, $status, $changedBy, $note]
    );

    return true;
}

/**
 * Record a successful card payment and confirm the order.
 *
 * Written to be safe to call twice: the browser return and the Stripe
 * webhook both report the same payment, and whichever arrives second
 * must not create a duplicate payment row.
 */
function mark_order_paid(int $orderId, string $intentId, float $amount, string $currency = 'GBP'): void
{
    $already = db_value(
        'SELECT 1 FROM payments WHERE stripe_intent_id = ? LIMIT 1',
        [$intentId]
    );

    if (!$already) {
        db_run(
            'INSERT INTO payments (order_id, stripe_intent_id, amount, currency, method, status)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$orderId, $intentId, $amount, $currency, 'card', 'succeeded']
        );
    }

    db_run(
        "UPDATE orders
         SET payment_status = 'paid', payment_intent_id = ?
         WHERE id = ?",
        [$intentId, $orderId]
    );

    set_order_status($orderId, 'confirmed', null, 'Card payment received');
}

/** Record a failed card payment without confirming the order. */
function mark_payment_failed(int $orderId, string $intentId, float $amount, string $message = ''): void
{
    db_run(
        'INSERT INTO payments (order_id, stripe_intent_id, amount, currency, method, status, failure_message)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$orderId, $intentId, $amount, setting('currency_code', 'GBP'), 'card', 'failed', $message ?: null]
    );

    db_run(
        "UPDATE orders SET payment_status = 'failed', payment_intent_id = ? WHERE id = ?",
        [$intentId, $orderId]
    );
}

/**
 * The statuses an order can legitimately move to next.
 *
 * Kitchen staff work under pressure, so the interface offers only the
 * transitions that make sense rather than a list of every status, which
 * removes a whole class of mis-taps.
 */
function next_statuses(array $order): array
{
    if (in_array($order['status'], ['completed', 'cancelled'], true)) {
        return [];
    }

    $isDelivery = $order['order_type'] === 'delivery';

    return match ($order['status']) {
        'pending'          => ['confirmed', 'cancelled'],
        'confirmed'        => ['preparing', 'cancelled'],
        'preparing'        => ['ready', 'cancelled'],
        'ready'            => $isDelivery ? ['out_for_delivery'] : ['completed'],
        'out_for_delivery' => ['completed'],
        default            => [],
    };
}

/** Wording for the button that performs a transition. */
function transition_label(string $status, array $order): string
{
    return match ($status) {
        'confirmed'        => 'Accept order',
        'preparing'        => 'Start cooking',
        'ready'            => $order['order_type'] === 'pickup' ? 'Ready to collect' : 'Ready',
        'out_for_delivery' => 'Out for delivery',
        'completed'        => $order['order_type'] === 'pickup' ? 'Collected' : 'Delivered',
        'cancelled'        => 'Cancel',
        default            => status_label($status),
    };
}

/* ------------------------------------------------------------------ */
/* The live board                                                      */
/* ------------------------------------------------------------------ */

/**
 * Orders still needing kitchen attention, oldest first.
 *
 * @param int|null $restaurantId null means every restaurant, which only
 *                               a platform administrator ever passes.
 */
function active_orders(?int $restaurantId): array
{
    $sql = "SELECT o.*, r.name AS restaurant_name
            FROM orders o
            JOIN restaurants r ON r.id = o.restaurant_id
            WHERE o.status IN ('pending','confirmed','preparing','ready','out_for_delivery')";
    $params = [];

    if ($restaurantId) {
        $sql .= ' AND o.restaurant_id = ?';
        $params[] = $restaurantId;
    }

    $sql .= ' ORDER BY o.created_at';

    return db_all($sql, $params);
}

/**
 * A cheap fingerprint of the board used by the polling endpoint.
 * If nothing has changed, the value is identical and the browser does
 * nothing, which keeps the poll far lighter than re-sending every order.
 */
function board_stamp(?int $restaurantId): string
{
    $sql = "SELECT COUNT(*) AS total, COALESCE(MAX(UNIX_TIMESTAMP(updated_at)), 0) AS latest
            FROM orders
            WHERE status IN ('pending','confirmed','preparing','ready','out_for_delivery')";
    $params = [];

    if ($restaurantId) {
        $sql .= ' AND restaurant_id = ?';
        $params[] = $restaurantId;
    }

    $row = db_one($sql, $params);

    return ($row['total'] ?? 0) . ':' . ($row['latest'] ?? 0);
}

/* ------------------------------------------------------------------ */
/* Customer tracking                                                   */
/* ------------------------------------------------------------------ */

/**
 * The steps shown on the tracking timeline, each marked done, current or
 * upcoming. Collection orders skip the delivery step entirely.
 */
function order_timeline(array $order): array
{
    if ($order['status'] === 'cancelled') {
        return [
            ['label' => 'Order placed', 'state' => 'done'],
            ['label' => 'Cancelled',    'state' => 'current'],
        ];
    }

    $isDelivery = $order['order_type'] === 'delivery';

    $steps = [
        'pending'   => 'Order placed',
        'confirmed' => 'Confirmed by the kitchen',
        'preparing' => 'Being prepared',
        'ready'     => $isDelivery ? 'Ready to leave' : 'Ready to collect',
    ];

    if ($isDelivery) {
        $steps['out_for_delivery'] = 'Out for delivery';
    }

    $steps['completed'] = $isDelivery ? 'Delivered' : 'Collected';

    $order_of_status = array_keys($steps);
    $currentIndex = array_search($order['status'], $order_of_status, true);

    if ($currentIndex === false) {
        $currentIndex = 0;
    }

    $timeline = [];
    foreach ($order_of_status as $index => $key) {
        $timeline[] = [
            'label' => $steps[$key],
            'state' => $index < $currentIndex ? 'done'
                     : ($index === $currentIndex ? 'current' : 'upcoming'),
        ];
    }

    return $timeline;
}

/* ------------------------------------------------------------------ */
/* Reordering                                                          */
/* ------------------------------------------------------------------ */

/**
 * Put the lines of a past order back into the basket.
 *
 * Because a basket belongs to one restaurant, reordering replaces
 * whatever was there if it came from somewhere else. The caller is told
 * so it can explain that to the customer rather than letting a basket
 * change hands silently.
 *
 * @return array{added:int, skipped:string[], replaced:bool}
 */
function reorder_into_basket(int $orderId): array
{
    $order = order_by_id($orderId);
    $added = 0;
    $skipped = [];
    $replaced = false;

    if (!$order) {
        return ['added' => 0, 'skipped' => ['That order could not be found'], 'replaced' => false];
    }

    $restaurant = restaurant_by_id((int) $order['restaurant_id']);

    if (!restaurant_is_public($restaurant)) {
        return [
            'added'    => 0,
            'skipped'  => ['That restaurant is no longer on Smart Eats'],
            'replaced' => false,
        ];
    }

    // A basket from a different restaurant is cleared, not merged.
    if (cart_restaurant_id() && cart_restaurant_id() !== (int) $order['restaurant_id']) {
        cart_clear();
        $replaced = true;
    }

    set_cart_restaurant((int) $order['restaurant_id']);

    foreach (order_lines($orderId) as $line) {
        $itemId = (int) $line['menu_item_id'];

        if (!$itemId) {
            $skipped[] = $line['item_name'] . ' is no longer on the menu';
            continue;
        }

        $item = db_one(
            'SELECT id, name, is_available, is_active, restaurant_id
             FROM menu_items WHERE id = ? LIMIT 1',
            [$itemId]
        );

        if (!$item
            || (int) $item['is_active'] !== 1
            || (int) $item['restaurant_id'] !== (int) $order['restaurant_id']) {
            $skipped[] = $line['item_name'] . ' is no longer on the menu';
            continue;
        }

        if ((int) $item['is_available'] !== 1) {
            $skipped[] = $item['name'] . ' is unavailable today';
            continue;
        }

        $quantity = min(20, (int) $line['quantity']);
        $_SESSION['cart'][$itemId] = min(20, ($_SESSION['cart'][$itemId] ?? 0) + $quantity);
        $added++;
    }

    if ($added === 0 && !cart()) {
        cart_clear();
    }

    return ['added' => $added, 'skipped' => $skipped, 'replaced' => $replaced];
}

/* ------------------------------------------------------------------ */
/* Reviews                                                             */
/* ------------------------------------------------------------------ */

/** A review is only possible once the customer has received the food. */
function order_is_reviewable(array $order): bool
{
    return $order['status'] === 'completed';
}

/** Lines from this order that the customer has not rated yet. */
function unreviewed_lines(int $orderId): array
{
    return db_all(
        'SELECT oi.menu_item_id, oi.item_name
         FROM order_items oi
         WHERE oi.order_id = ?
           AND oi.menu_item_id IS NOT NULL
           AND NOT EXISTS (
             SELECT 1 FROM reviews r
              WHERE r.order_id = oi.order_id
                AND r.menu_item_id = oi.menu_item_id
           )
         GROUP BY oi.menu_item_id, oi.item_name',
        [$orderId]
    );
}

/**
 * Has the customer still not rated the restaurant for this order?
 * Separate from the dish ratings, because the two are asked separately
 * and either one on its own is a reason to offer the review link.
 */
function restaurant_review_pending(int $orderId): bool
{
    return !db_value('SELECT 1 FROM restaurant_reviews WHERE order_id = ?', [$orderId]);
}

/** How many dishes on this order are still waiting for a rating. */
function pending_review_count(int $orderId): int
{
    return count(unreviewed_lines($orderId));
}

/**
 * Average rating and review count for a set of menu items, keyed by id.
 * One query for a whole menu page rather than one per dish.
 */
function ratings_for(array $itemIds): array
{
    $itemIds = array_filter(array_map('intval', $itemIds));

    if (!$itemIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

    $rows = db_all(
        "SELECT menu_item_id, ROUND(AVG(rating), 1) AS average, COUNT(*) AS total
         FROM reviews
         WHERE menu_item_id IN ($placeholders)
         GROUP BY menu_item_id",
        $itemIds
    );

    return array_column($rows, null, 'menu_item_id');
}
