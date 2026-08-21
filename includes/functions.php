<?php
/**
 * Smart Eats - shared helper functions
 */

require_once __DIR__ . '/../config/db.php';

/* ------------------------------------------------------------------ */
/* Output escaping                                                     */
/* ------------------------------------------------------------------ */

/** Escape a value for safe output in HTML. Use on every echoed variable. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build a full URL from a path relative to the project root. */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/** Redirect and stop execution. */
function redirect(string $path): void
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)));
    exit;
}

/** True when the current request is a form POST. */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

/** Read and trim a request value. */
function input(string $key, $default = ''): string
{
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : (string) $default;
}

/* ------------------------------------------------------------------ */
/* CSRF protection                                                     */
/* ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input to drop inside every form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Verify a submitted token. Ends the request on failure. */
function verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
        http_response_code(419);
        exit('Your session expired. Reload the page and try again.');
    }
}

/* ------------------------------------------------------------------ */
/* Flash messages                                                      */
/* ------------------------------------------------------------------ */

/** Queue a message for the next page load. Type: success, error, info. */
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

/** Return and clear queued messages. */
function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/* ------------------------------------------------------------------ */
/* Platform settings                                                   */
/* ------------------------------------------------------------------ */
/*
 * From Phase 11 the settings table holds platform-wide values only. Fees,
 * minimums, VAT, opening hours and contact details belong to individual
 * restaurants and are columns on the restaurants table instead.
 */

/** Read a value from the settings table, cached for the request. */
function setting(string $key, string $default = ''): string
{
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        foreach (db_all('SELECT setting_key, setting_value FROM settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }

    return $cache[$key] ?? $default;
}

/* ------------------------------------------------------------------ */
/* Formatting                                                          */
/* ------------------------------------------------------------------ */

/** Format an amount using the configured currency symbol. */
function money($amount): string
{
    return setting('currency_symbol', '£') . number_format((float) $amount, 2);
}

/** Human readable date, e.g. 4 Aug 2026, 18:42 */
function pretty_date(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    return date('j M Y, H:i', strtotime($datetime));
}

/** Label for an order status code. */
function status_label(string $status): string
{
    global $ORDER_STATUSES;
    return $ORDER_STATUSES[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/**
 * URL for a menu item photo, falling back to the shared placeholder when
 * no image has been uploaded or the file is missing from disk.
 */
function menu_image_url(?string $image): string
{
    if ($image && is_file(UPLOADS_PATH . '/menu/' . $image)) {
        return UPLOADS_URL . '/menu/' . rawurlencode($image);
    }
    return ASSETS_URL . '/img/placeholder.svg';
}

/** Shorten text for card summaries without cutting a word in half. */
function excerpt(?string $text, int $limit = 110): string
{
    $text = trim((string) $text);
    if ($text === '' || str_len($text) <= $limit) {
        return $text;
    }
    $cut = substr($text, 0, $limit);
    $lastSpace = strrpos($cut, ' ');
    return rtrim($lastSpace ? substr($cut, 0, $lastSpace) : $cut, ' ,.;:') . '...';
}

/* ------------------------------------------------------------------ */
/* Orders                                                              */
/* ------------------------------------------------------------------ */

/**
 * Generate a unique, human-friendly order reference, e.g. SE-260804-4831.
 * Staff read these aloud to customers, so it is short and unambiguous.
 * The reference is unique across the whole platform rather than per
 * restaurant, so a customer with orders from two kitchens can never hold
 * two slips carrying the same number.
 */
function generate_order_number(): string
{
    do {
        $number = 'SE-' . date('ymd') . '-' . random_int(1000, 9999);
        $exists = db_value('SELECT 1 FROM orders WHERE order_number = ?', [$number]);
    } while ($exists);

    return $number;
}

/* ------------------------------------------------------------------ */
/* Session cart                                                        */
/* ------------------------------------------------------------------ */
/*
 * The basket lives in $_SESSION rather than the database. This keeps
 * guest checkout possible without creating throwaway account rows, and
 * suits the scale of the platform. Structure:
 *
 *   $_SESSION['cart']               = [ menu_item_id => quantity, ... ]
 *   $_SESSION['cart_restaurant_id'] = the one restaurant it belongs to
 *
 * Every basket belongs to exactly one restaurant. The id is stored
 * alongside the lines rather than derived from them, so an empty basket
 * still knows nothing is reserved and a basket whose last dish was
 * withdrawn does not silently change hands.
 */

function cart(): array
{
    return $_SESSION['cart'] ?? [];
}

/** Total number of units in the basket, for the header badge. */
function cart_count(): int
{
    return array_sum(cart());
}

/** The restaurant this basket belongs to, or null when it is empty. */
function cart_restaurant_id(): ?int
{
    $id = (int) ($_SESSION['cart_restaurant_id'] ?? 0);
    return $id > 0 ? $id : null;
}

/** The full restaurant row for the current basket, or null. */
function cart_restaurant(): ?array
{
    require_once __DIR__ . '/restaurants.php';
    return restaurant_by_id(cart_restaurant_id());
}

/** Point an empty basket at a restaurant. */
function set_cart_restaurant(int $restaurantId): void
{
    $_SESSION['cart_restaurant_id'] = $restaurantId;
}

/** Load the basket as full rows joined to the menu, with line totals. */
function cart_items(): array
{
    $cart = cart();
    $restaurantId = cart_restaurant_id();

    if (!$cart || !$restaurantId) {
        return [];
    }

    $ids = array_map('intval', array_keys($cart));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // The restaurant is part of the condition, not merely selected. A
    // dish that somehow belongs to another restaurant is not priced into
    // this basket at all.
    $rows = db_all(
        "SELECT id, name, price, image, is_available, restaurant_id
         FROM menu_items
         WHERE id IN ($placeholders) AND is_active = 1 AND restaurant_id = ?",
        array_merge($ids, [$restaurantId])
    );

    $items = [];
    foreach ($rows as $row) {
        $qty = (int) $cart[$row['id']];
        $row['quantity']   = $qty;
        $row['line_total'] = round($row['price'] * $qty, 2);
        $items[] = $row;
    }

    return $items;
}

/**
 * Subtotal, delivery fee, tax and total for the current basket.
 *
 * The fee, the free-delivery threshold and the VAT rate all come from the
 * basket's own restaurant, so two baskets open in two tabs are each
 * priced by the kitchen that will cook them.
 */
function cart_totals(string $orderType = 'delivery'): array
{
    $restaurant = cart_restaurant();

    $subtotal = 0.0;
    foreach (cart_items() as $item) {
        $subtotal += $item['line_total'];
    }

    $fee = 0.0;
    if ($restaurant && $orderType === 'delivery' && $subtotal > 0) {
        $freeOver = (float) $restaurant['free_delivery_over'];
        $fee = ($freeOver > 0 && $subtotal >= $freeOver)
            ? 0.0
            : (float) $restaurant['delivery_fee'];
    }

    $tax = $restaurant
        ? round($subtotal * (float) $restaurant['tax_rate'], 2)
        : 0.0;

    return [
        'subtotal'     => round($subtotal, 2),
        'delivery_fee' => round($fee, 2),
        'tax'          => $tax,
        'total'        => round($subtotal + $fee + $tax, 2),
    ];
}

function cart_clear(): void
{
    unset($_SESSION['cart'], $_SESSION['cart_restaurant_id']);
}

/** Delivery or collection, remembered for the session. */
function current_order_type(): string
{
    return ($_SESSION['order_type'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
}

/** Store the chosen order type. Returns false for an unknown value. */
function set_order_type(string $type): bool
{
    if (!in_array($type, ['delivery', 'pickup'], true)) {
        return false;
    }
    $_SESSION['order_type'] = $type;
    return true;
}

/**
 * Problems that must be resolved before checkout: dishes that sold out
 * or were withdrawn while sitting in the basket, an order below the
 * restaurant's minimum, and a restaurant that has closed or been
 * suspended since the basket was filled. Returns plain-language messages.
 */
function cart_problems(): array
{
    require_once __DIR__ . '/restaurants.php';

    $problems = [];
    $cart     = cart();

    if (!$cart) {
        return $problems;
    }

    $restaurant = cart_restaurant();

    // A restaurant withdrawn from the platform takes its basket with it.
    if (!restaurant_is_public($restaurant)) {
        cart_clear();
        return ['That restaurant is no longer available, so your basket has been emptied.'];
    }

    if (!restaurant_is_open($restaurant)) {
        $problems[] = restaurant_closed_reason($restaurant);
    }

    $ids = array_map('intval', array_keys($cart));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $rows = db_all(
        "SELECT id, name, is_available, is_active, restaurant_id
         FROM menu_items WHERE id IN ($placeholders)",
        $ids
    );
    $found = array_column($rows, null, 'id');

    foreach ($ids as $id) {
        $item = $found[$id] ?? null;

        if (!$item
            || (int) $item['is_active'] !== 1
            || (int) $item['restaurant_id'] !== (int) $restaurant['id']) {
            $problems[] = 'One dish has been taken off the menu and removed from your basket.';
            unset($_SESSION['cart'][$id]);
        } elseif ((int) $item['is_available'] !== 1) {
            $problems[] = $item['name'] . ' is unavailable today. Remove it to continue.';
        }
    }

    // An emptied basket no longer belongs to anyone.
    if (!cart()) {
        cart_clear();
        return array_values(array_unique($problems));
    }

    $minimum = (float) $restaurant['min_order_value'];
    $totals  = cart_totals(current_order_type());

    if ($minimum > 0 && $totals['subtotal'] > 0 && $totals['subtotal'] < $minimum) {
        $problems[] = 'The minimum order at ' . $restaurant['name'] . ' is ' . money($minimum)
                    . '. Add ' . money($minimum - $totals['subtotal']) . ' more to continue.';
    }

    return array_values(array_unique($problems));
}

/* ------------------------------------------------------------------ */
/* Validation                                                          */
/* ------------------------------------------------------------------ */

/**
 * Character count that works whether or not the mbstring extension is
 * enabled. XAMPP ships with mbstring, but falling back keeps the system
 * running on a minimal PHP install.
 */
function str_len(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function is_valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Accepts UK-style numbers with spaces, dashes or a country code.
 * Deliberately permissive: an over-strict rule blocks real customers.
 */
function is_valid_phone(string $phone): bool
{
    $digits = preg_replace('/\D+/', '', $phone);
    return strlen($digits) >= 9 && strlen($digits) <= 15;
}

/**
 * Returns an error message if the password is too weak, or null if it is
 * acceptable. Requires length plus a mix of letters and digits.
 */
function password_problem(string $password): ?string
{
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return 'Use at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Include at least one letter and one number.';
    }
    return null;
}

/** Print the CSS error class for a field, if it has an error. */
function field_class(array $errors, string $key): string
{
    return isset($errors[$key]) ? 'field has-error' : 'field';
}

/** Print a field-level error message, if there is one. */
function field_error(array $errors, string $key): string
{
    return isset($errors[$key])
        ? '<span class="field__error">' . e($errors[$key]) . '</span>'
        : '';
}

/** Client IP address, falling back to a placeholder on the CLI. */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/* ------------------------------------------------------------------ */
/* JSON responses for the AJAX endpoints in /api                       */
/* ------------------------------------------------------------------ */

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_response(['ok' => false, 'error' => $message], $status);
}
