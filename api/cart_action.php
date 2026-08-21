<?php
/**
 * Smart Eats - basket endpoint
 *
 * Handles every change to the session basket and always replies with the
 * current basket summary, so the caller never has to work out the new
 * totals for itself.
 *
 * POST JSON: { "action": "add|update|remove|clear|order_type|switch",
 *              "item_id": 12, "quantity": 2 }
 *
 * The item price is never accepted from the client. Prices are read from
 * the database whenever totals are calculated.
 *
 * PHASE 11 - one basket, one restaurant
 * Adding a dish from a different restaurant does not merge and does not
 * silently replace. It answers with needs_switch, naming both
 * restaurants, and the browser asks the customer. Only a second request
 * carrying "switch" empties the basket and starts again. Losing a
 * half-built order without being asked is the kind of thing that ends a
 * usability session badly.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restaurants.php';

if (!is_post()) {
    json_error('This endpoint accepts POST requests only.', 405);
}

// Accept a JSON body or a normal form post.
$raw   = file_get_contents('php://input');
$body  = json_decode($raw ?: '[]', true);
$input = is_array($body) && $body ? $body : $_POST;

// CSRF check works for both transports.
$token = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    json_error('Your session expired. Reload the page and try again.', 419);
}

$action   = (string) ($input['action'] ?? 'add');
$itemId   = (int) ($input['item_id'] ?? 0);
$quantity = (int) ($input['quantity'] ?? 1);
$switch   = !empty($input['switch']);

// The platform can be paused wholesale for maintenance. Removing items
// and emptying the basket stay available, since a customer should never
// be trapped with a basket they cannot clear.
if (!platform_is_open() && in_array($action, ['add', 'update'], true)) {
    json_error('Smart Eats has paused ordering for maintenance. Please try again shortly.', 503);
}

const CART_MAX_PER_ITEM = 20;

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

/** Current basket summary returned with every response. */
function cart_summary(): array
{
    $type       = current_order_type();
    $totals     = cart_totals($type);
    $restaurant = cart_restaurant();

    return [
        'count'        => cart_count(),
        'order_type'   => $type,
        'restaurant'   => $restaurant ? [
            'id'   => (int) $restaurant['id'],
            'name' => $restaurant['name'],
            'slug' => $restaurant['slug'],
        ] : null,
        'subtotal'     => $totals['subtotal'],
        'delivery_fee' => $totals['delivery_fee'],
        'tax'          => $totals['tax'],
        'total'        => $totals['total'],
        'below_minimum' => $restaurant
            && $totals['subtotal'] > 0
            && $totals['subtotal'] < (float) $restaurant['min_order_value'],
        'formatted' => [
            'subtotal'     => money($totals['subtotal']),
            'delivery_fee' => $totals['delivery_fee'] > 0
                ? money($totals['delivery_fee'])
                : 'Free',
            'tax'          => money($totals['tax']),
            'total'        => money($totals['total']),
        ],
    ];
}

switch ($action) {

    case 'order_type':
        $type = (string) ($input['order_type'] ?? '');
        if (!set_order_type($type)) {
            json_error('Choose either delivery or collection.');
        }
        json_response([
            'ok'      => true,
            'message' => $type === 'pickup'
                ? 'Switched to collection. No delivery fee.'
                : 'Switched to delivery.',
            'cart'    => cart_summary(),
        ]);
        break;

    case 'clear':
        cart_clear();
        json_response([
            'ok'      => true,
            'message' => 'Basket emptied.',
            'cart'    => cart_summary(),
        ]);
        break;

    case 'add':
    case 'update':
        if ($itemId <= 0) {
            json_error('That dish could not be identified.');
        }

        // The dish is read with its restaurant, because the restaurant's
        // state decides whether it may be ordered at all.
        $item = db_one(
            'SELECT m.id, m.name, m.price, m.is_available, m.restaurant_id,
                    r.name AS restaurant_name, r.slug AS restaurant_slug,
                    r.approval_status, r.is_accepting_orders
             FROM menu_items m
             JOIN restaurants r ON r.id = m.restaurant_id
             WHERE m.id = ? AND m.is_active = 1
             LIMIT 1',
            [$itemId]
        );

        if (!$item) {
            json_error('That dish is no longer on the menu.', 404);
        }

        if ($item['approval_status'] !== 'approved') {
            json_error('That restaurant is not available on Smart Eats.', 403);
        }

        if ((int) $item['is_accepting_orders'] !== 1) {
            json_error($item['restaurant_name'] . ' has paused online ordering.', 503);
        }

        if ((int) $item['is_available'] !== 1) {
            json_error($item['name'] . ' is unavailable today.', 409);
        }

        $itemVendorId = (int) $item['restaurant_id'];
        $cartVendorId = cart_restaurant_id();

        // ---------------------------------------------------------
        // The one-basket-one-restaurant rule
        // ---------------------------------------------------------
        if ($cartVendorId && $cartVendorId !== $itemVendorId && cart_count() > 0) {

            if (!$switch) {
                $currentVendor = cart_restaurant();

                // 409 rather than an error the browser shows as a
                // failure: nothing has gone wrong, a decision is needed.
                json_response([
                    'ok'           => false,
                    'needs_switch' => true,
                    'error'        => 'Your basket has items from ' . $currentVendor['name'] . '.',
                    'message'      => 'Start a new basket with ' . $item['restaurant_name']
                                    . '? The items from ' . $currentVendor['name'] . ' will be removed.',
                    'from'         => $currentVendor['name'],
                    'to'           => $item['restaurant_name'],
                    'cart'         => cart_summary(),
                ], 409);
            }

            // Confirmed: start again with the new restaurant.
            cart_clear();
            $_SESSION['cart'] = [];
        }

        set_cart_restaurant($itemVendorId);

        if ($quantity < 1) {
            $quantity = 1;
        }

        $existing = (int) ($_SESSION['cart'][$itemId] ?? 0);
        $newQty   = ($action === 'add') ? $existing + $quantity : $quantity;

        if ($newQty > CART_MAX_PER_ITEM) {
            $newQty = CART_MAX_PER_ITEM;
            $capped = true;
        }

        $_SESSION['cart'][$itemId] = $newQty;

        if (isset($capped)) {
            $message = 'You can order up to ' . CART_MAX_PER_ITEM
                     . ' of one dish. Call the restaurant for larger orders.';
        } elseif ($action === 'update') {
            $message = $item['name'] . ' updated to ' . $newQty . '.';
        } elseif ($switch) {
            $message = 'New basket started with ' . $item['restaurant_name'] . '.';
        } else {
            $message = $item['name'] . ' added to your basket.';
        }

        json_response([
            'ok'      => true,
            'message' => $message,
            'item'    => ['id' => (int) $item['id'], 'quantity' => $newQty],
            'cart'    => cart_summary(),
        ]);
        break;

    case 'remove':
        if ($itemId <= 0 || !isset($_SESSION['cart'][$itemId])) {
            json_error('That dish is not in your basket.', 404);
        }

        $name = db_value('SELECT name FROM menu_items WHERE id = ?', [$itemId]);
        unset($_SESSION['cart'][$itemId]);

        // An empty basket belongs to nobody, so it forgets its restaurant
        // and the next dish added can come from anywhere.
        if (!cart()) {
            cart_clear();
        }

        json_response([
            'ok'      => true,
            'message' => ($name ?: 'Dish') . ' removed from your basket.',
            'cart'    => cart_summary(),
        ]);
        break;

    default:
        json_error('Unknown basket action.');
}
