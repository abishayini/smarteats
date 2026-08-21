<?php
/**
 * Smart Eats - session handling and access control
 *
 * Include this at the top of every page. It starts the session, enforces
 * the idle timeout, and exposes the role guards used by the vendor,
 * staff and platform admin areas.
 *
 * Four roles exist from Phase 11:
 *   customer  browses and orders
 *   staff     one restaurant's live order board
 *   vendor    one restaurant's menu, orders, staff and settings
 *   admin     the platform: approves restaurants, sees everything
 */

require_once __DIR__ . '/functions.php';

/* ------------------------------------------------------------------ */
/* Session start                                                       */
/* ------------------------------------------------------------------ */

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,                        // not readable by JavaScript
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),   // becomes true once TLS is in place
    ]);
    session_start();
}

/* ------------------------------------------------------------------ */
/* Idle timeout                                                        */
/* ------------------------------------------------------------------ */

if (isset($_SESSION['last_activity'])
    && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    $wasLoggedIn = isset($_SESSION['user_id']);
    session_unset();
    session_destroy();
    session_start();
    if ($wasLoggedIn) {
        flash('You were signed out after a period of inactivity.', 'info');
    }
}
$_SESSION['last_activity'] = time();

/* ------------------------------------------------------------------ */
/* Authentication state                                                */
/* ------------------------------------------------------------------ */

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/** The signed-in user row, or null. Cached for the request. */
function current_user(): ?array
{
    static $user = null;
    static $loaded = false;

    if ($loaded) {
        return $user;
    }
    $loaded = true;

    if (!is_logged_in()) {
        return null;
    }

    $user = db_one(
        'SELECT id, full_name, email, phone, address, role, restaurant_id, is_active
         FROM users WHERE id = ? LIMIT 1',
        [$_SESSION['user_id']]
    );

    // Account deleted or deactivated while the session was open.
    if (!$user || (int) $user['is_active'] !== 1) {
        logout_user();
        $user = null;
    }

    return $user;
}

function current_role(): string
{
    $user = current_user();
    return $user['role'] ?? 'guest';
}

/** True if the signed-in user holds any of the given roles. */
function has_role(string ...$roles): bool
{
    return in_array(current_role(), $roles, true);
}

/** The restaurant id on the signed-in account, or null. */
function user_restaurant_id(): ?int
{
    $user = current_user();
    $id = (int) ($user['restaurant_id'] ?? 0);
    return $id > 0 ? $id : null;
}

/* ------------------------------------------------------------------ */
/* Login and logout                                                    */
/* ------------------------------------------------------------------ */

/**
 * Establish a session for a user row. The session id is regenerated to
 * prevent session fixation.
 */
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']       = (int) $user['id'];
    $_SESSION['user_role']     = $user['role'];
    $_SESSION['user_name']     = $user['full_name'];
    $_SESSION['last_activity'] = time();
    unset($_SESSION['csrf_token']);          // issue a fresh token after login
    unset($_SESSION['panel_restaurant_id']); // never inherit another user's scope
}

function logout_user(): void
{
    // Keep the basket across sign-out, along with the restaurant it
    // belongs to; losing one without the other would leave dishes with
    // no kitchen attached.
    $cart       = $_SESSION['cart'] ?? null;
    $cartVendor = $_SESSION['cart_restaurant_id'] ?? null;

    session_unset();
    session_regenerate_id(true);

    if ($cart && $cartVendor) {
        $_SESSION['cart'] = $cart;
        $_SESSION['cart_restaurant_id'] = $cartVendor;
    }

    $_SESSION['last_activity'] = time();
}

/* ------------------------------------------------------------------ */
/* Brute-force protection                                              */
/* ------------------------------------------------------------------ */

define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

/** Record a sign-in attempt against an email address. */
function record_login_attempt(string $email, bool $success): void
{
    db_run(
        'INSERT INTO login_attempts (email, ip_address, was_successful)
         VALUES (?, ?, ?)',
        [strtolower($email), client_ip(), $success ? 1 : 0]
    );

    // A successful sign-in clears the recent failures for that address.
    if ($success) {
        db_run(
            'DELETE FROM login_attempts
             WHERE email = ? AND was_successful = 0',
            [strtolower($email)]
        );
    }
}

/** Number of failed attempts for an email inside the lockout window. */
function recent_failed_attempts(string $email): int
{
    return (int) db_value(
        'SELECT COUNT(*) FROM login_attempts
         WHERE email = ? AND was_successful = 0
           AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
        [strtolower($email), LOGIN_LOCKOUT_MINUTES]
    );
}

/** True when the address has exceeded the allowed number of attempts. */
function is_locked_out(string $email): bool
{
    return recent_failed_attempts($email) >= LOGIN_MAX_ATTEMPTS;
}

/* ------------------------------------------------------------------ */
/* Guards                                                              */
/* ------------------------------------------------------------------ */

/**
 * Require a signed-in user. Stores the requested page so the user is
 * returned there after logging in.
 */
function require_login(): void
{
    if (!is_logged_in() || !current_user()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        flash('Please sign in to continue.', 'info');
        redirect('login.php');
    }
}

/**
 * Require one of the given roles. Access is checked on the server for
 * every protected page, not merely by hiding links in the navigation.
 */
function require_role(string ...$roles): void
{
    require_login();

    if (!has_role(...$roles)) {
        http_response_code(403);
        flash('You do not have permission to open that page.', 'error');
        redirect(home_for_role(current_role()));
    }
}

/**
 * A vendor or staff member whose restaurant has been suspended keeps
 * their account but must not carry on trading. Panel pages call this
 * after require_role() so the block is enforced server-side.
 */
function require_active_restaurant(): void
{
    require_once __DIR__ . '/restaurants.php';

    $user = current_user();

    if (!$user || !in_array($user['role'], ['vendor', 'staff'], true)) {
        return;
    }

    $restaurant = restaurant_by_id((int) $user['restaurant_id']);

    if (!$restaurant) {
        flash('Your account is not linked to a restaurant. Contact Smart Eats support.', 'error');
        redirect('index.php');
    }

    if ($restaurant['approval_status'] === 'suspended') {
        flash(
            $restaurant['name'] . ' has been suspended by Smart Eats. '
            . 'Contact support before taking further orders.',
            'error'
        );
        redirect('index.php');
    }
}

/** Where a user should land immediately after signing in. */
function home_for_role(string $role): string
{
    // Vendors and platform administrators share the same panel screens.
    // Each screen scopes itself through panel_restaurant(), so there is
    // no second copy of the menu, order or settings pages to maintain.
    return match ($role) {
        'admin', 'vendor' => 'admin/dashboard.php',
        'staff'           => 'staff/dashboard.php',
        default           => 'index.php',
    };
}
