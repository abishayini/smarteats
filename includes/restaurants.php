<?php
/**
 * Smart Eats - restaurants
 *
 * Everything to do with the sellers on the platform: looking them up,
 * deciding whether a customer may see them, and working out which
 * restaurant the person signed into the panel is allowed to manage.
 *
 * The scoping helpers at the bottom of this file are the security
 * boundary of the whole multi-restaurant model. Every panel query filters
 * on the value they return, so a vendor cannot reach another vendor's
 * menu, orders or staff by editing an id in the address bar.
 */

// auth.php is loaded rather than functions.php alone, because the
// scoping helpers below depend on current_user() and has_role(). Loading
// it here means this file can be included on its own and still behave.
// auth.php loads functions.php in turn, so nothing is missed.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/hours.php';

/* ------------------------------------------------------------------ */
/* Lookup                                                              */
/* ------------------------------------------------------------------ */

/** One restaurant by id, whatever its approval state. Cached per request. */
function restaurant_by_id(?int $id): ?array
{
    static $cache = [];

    if (!$id) {
        return null;
    }

    if (!array_key_exists($id, $cache)) {
        $cache[$id] = db_one('SELECT * FROM restaurants WHERE id = ? LIMIT 1', [$id]);
    }

    return $cache[$id];
}

/** One restaurant by its URL slug. */
function restaurant_by_slug(string $slug): ?array
{
    if ($slug === '') {
        return null;
    }
    return db_one('SELECT * FROM restaurants WHERE slug = ? LIMIT 1', [$slug]);
}

/**
 * Restaurants a customer is allowed to browse.
 *
 * @param array{search?:string, cuisine?:string, open_only?:bool} $filters
 */
function public_restaurants(array $filters = []): array
{
    // The rating combines dish reviews and restaurant reviews.
    //
    // Written as four scalar subqueries rather than the more obvious
    // UNION inside a derived table, because a derived table cannot see
    // the outer query's columns: MySQL rejects `WHERE restaurant_id =
    // r.id` inside a FROM subquery with "Unknown column 'r.id'". A
    // scalar subquery in the SELECT list can be correlated, so the sums
    // and counts are fetched separately and combined here.
    //
    // NULLIF guards the division: a restaurant with no reviews at all
    // gives 0 on the bottom, and NULL is the right answer for "no
    // rating" rather than a division error.
    $sql = "SELECT r.*,
                   (SELECT COUNT(*) FROM menu_items m
                     WHERE m.restaurant_id = r.id AND m.is_active = 1) AS dish_count,
                   ROUND(
                     (COALESCE((SELECT SUM(rv.rating) FROM reviews rv
                                 WHERE rv.restaurant_id = r.id), 0)
                    + COALESCE((SELECT SUM(rr.rating) FROM restaurant_reviews rr
                                 WHERE rr.restaurant_id = r.id), 0))
                     / NULLIF(
                       (SELECT COUNT(*) FROM reviews rv
                         WHERE rv.restaurant_id = r.id)
                     + (SELECT COUNT(*) FROM restaurant_reviews rr
                         WHERE rr.restaurant_id = r.id), 0)
                   , 1) AS rating_average,
                   ((SELECT COUNT(*) FROM reviews rv
                      WHERE rv.restaurant_id = r.id)
                  + (SELECT COUNT(*) FROM restaurant_reviews rr
                      WHERE rr.restaurant_id = r.id)) AS rating_count
            FROM restaurants r
            WHERE r.approval_status = 'approved'";
    $params = [];

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $sql .= ' AND (r.name LIKE ? OR r.cuisine LIKE ? OR r.description LIKE ?
                       OR EXISTS (SELECT 1 FROM menu_items mi
                                   WHERE mi.restaurant_id = r.id
                                     AND mi.is_active = 1
                                     AND mi.name LIKE ?))';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $cuisine = trim((string) ($filters['cuisine'] ?? ''));
    if ($cuisine !== '') {
        $sql .= ' AND r.cuisine = ?';
        $params[] = $cuisine;
    }

    if (!empty($filters['open_only'])) {
        $sql .= ' AND r.is_accepting_orders = 1';
    }

    // Restaurants taking orders first, then alphabetically. A closed
    // kitchen still appears, because hiding it makes customers think the
    // restaurant has left the platform.
    $sql .= ' ORDER BY r.is_accepting_orders DESC, r.name';

    return db_all($sql, $params);
}

/** The cuisines actually in use, for the browse filter. */
function active_cuisines(): array
{
    return array_column(
        db_all(
            "SELECT DISTINCT cuisine FROM restaurants
             WHERE approval_status = 'approved' AND cuisine IS NOT NULL AND cuisine <> ''
             ORDER BY cuisine"
        ),
        'cuisine'
    );
}

/* ------------------------------------------------------------------ */
/* State                                                               */
/* ------------------------------------------------------------------ */

/** True when customers may see this restaurant at all. */
function restaurant_is_public(?array $restaurant): bool
{
    return $restaurant !== null && $restaurant['approval_status'] === 'approved';
}

/**
 * True when this restaurant is currently taking online orders.
 *
 * Four things all have to be true, and they are deliberately separate.
 * The platform switch is the administrator's; the approval state is the
 * platform's; the accepting-orders switch is the owner's immediate
 * control; and the schedule is the owner's routine one. A restaurant
 * that has not opted into a schedule is unaffected by the last of these.
 */
function restaurant_is_open(?array $restaurant): bool
{
    return restaurant_is_public($restaurant)
        && (int) $restaurant['is_accepting_orders'] === 1
        && platform_is_open()
        && restaurant_within_hours($restaurant);
}

/** The platform-wide kill switch, separate from any one restaurant. */
function platform_is_open(): bool
{
    return setting('platform_open', '1') === '1';
}

/** Plain-language reason a restaurant cannot be ordered from, or ''. */
function restaurant_closed_reason(?array $restaurant): string
{
    if (!$restaurant) {
        return 'That restaurant could not be found.';
    }
    if ($restaurant['approval_status'] === 'pending') {
        return 'This restaurant has not been approved yet, so it is not taking orders.';
    }
    if ($restaurant['approval_status'] === 'suspended') {
        return 'This restaurant is not currently available on Smart Eats.';
    }
    if (!platform_is_open()) {
        return 'Smart Eats is paused for maintenance. Ordering will return shortly.';
    }
    if ((int) $restaurant['is_accepting_orders'] !== 1) {
        return $restaurant['name'] . ' has paused online ordering. You can still browse the menu.';
    }
    if (!restaurant_within_hours($restaurant)) {
        // The schedule already knows when they open again, so say so
        // rather than leaving the customer to work it out.
        return $restaurant['name'] . ' is closed at the moment. '
             . todays_hours_line($restaurant) . '.';
    }
    return '';
}

/* ------------------------------------------------------------------ */
/* Presentation                                                        */
/* ------------------------------------------------------------------ */

/** URL of a restaurant's public menu page. */
function restaurant_url(array $restaurant): string
{
    return url('restaurant.php?r=' . urlencode($restaurant['slug']));
}

/** Logo URL, falling back to a lettered placeholder drawn in CSS. */
function restaurant_logo_url(?string $logo): string
{
    if ($logo && is_file(UPLOADS_PATH . '/logos/' . $logo)) {
        return UPLOADS_URL . '/logos/' . rawurlencode($logo);
    }
    return '';
}

/** Two-letter monogram used when a restaurant has no logo. */
function restaurant_monogram(string $name): string
{
    $words = preg_split('/\s+/', trim($name));
    $first = mb_substr($words[0] ?? 'S', 0, 1);
    $second = count($words) > 1 ? mb_substr(end($words), 0, 1) : '';
    return strtoupper($first . $second);
}

/** Delivery, minimum order and rating in one short line. */
function restaurant_summary_line(array $restaurant): string
{
    $bits = [];

    $bits[] = (float) $restaurant['min_order_value'] > 0
        ? money($restaurant['min_order_value']) . ' minimum'
        : 'No minimum';

    $bits[] = (float) $restaurant['delivery_fee'] > 0
        ? money($restaurant['delivery_fee']) . ' delivery'
        : 'Free delivery';

    if ((float) ($restaurant['free_delivery_over'] ?? 0) > 0) {
        $bits[] = 'free over ' . money($restaurant['free_delivery_over']);
    }

    return implode(' · ', $bits);
}

/**
 * Average rating and review count for one restaurant, or null.
 *
 * Two sources are combined: ratings left against individual dishes, and
 * ratings left against the restaurant itself. A customer who thought the
 * food was fine but the delivery was late has said something about the
 * restaurant, and a score that ignored it would be misleading.
 */
function restaurant_rating(int $restaurantId): ?array
{
    $row = db_one(
        'SELECT ROUND(AVG(r.rating), 1) AS average, COUNT(*) AS total
         FROM (
              SELECT rating FROM reviews             WHERE restaurant_id = ?
              UNION ALL
              SELECT rating FROM restaurant_reviews  WHERE restaurant_id = ?
         ) r',
        [$restaurantId, $restaurantId]
    );

    return ((int) ($row['total'] ?? 0) > 0) ? $row : null;
}

/** Service scores and recent comments left about the restaurant itself. */
function restaurant_service_rating(int $restaurantId): ?array
{
    $row = db_one(
        'SELECT ROUND(AVG(rating), 1)       AS average,
                ROUND(AVG(food_rating), 1)  AS food,
                ROUND(AVG(speed_rating), 1) AS speed,
                COUNT(*)                    AS total
         FROM restaurant_reviews WHERE restaurant_id = ?',
        [$restaurantId]
    );

    return ((int) ($row['total'] ?? 0) > 0) ? $row : null;
}

/* ------------------------------------------------------------------ */
/* Slugs                                                               */
/* ------------------------------------------------------------------ */

/** Turn a name into a URL-safe slug. */
function make_slug(string $text): string
{
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $text), '-'));
    return $slug;
}

/**
 * A slug that is not already taken, adding -2, -3 and so on if needed.
 * Two restaurants really can be called The Curry House.
 */
function unique_restaurant_slug(string $name, int $ignoreId = 0): string
{
    $base = make_slug($name) ?: 'restaurant';
    $slug = $base;
    $suffix = 1;

    while (db_value(
        'SELECT 1 FROM restaurants WHERE slug = ? AND id <> ?',
        [$slug, $ignoreId]
    )) {
        $suffix++;
        $slug = $base . '-' . $suffix;
    }

    return $slug;
}

/* ------------------------------------------------------------------ */
/* Panel scoping - the security boundary                               */
/* ------------------------------------------------------------------ */

/**
 * The restaurant whose data the signed-in panel user is working on.
 *
 * A vendor or staff member is fixed to the restaurant on their account
 * and cannot change it. A platform administrator has no restaurant of
 * their own, so they choose one from the switcher in the panel header,
 * remembered in the session.
 *
 * Returns null for an administrator who has not chosen one, which the
 * listing screens read as "every restaurant".
 */
function panel_restaurant(): ?array
{
    $user = current_user();

    if (!$user) {
        return null;
    }

    if (in_array($user['role'], ['vendor', 'staff'], true)) {
        return restaurant_by_id((int) $user['restaurant_id']);
    }

    if ($user['role'] === 'admin') {
        return restaurant_by_id((int) ($_SESSION['panel_restaurant_id'] ?? 0));
    }

    return null;
}

/** The id from panel_restaurant(), or null for an unscoped administrator. */
function panel_restaurant_id(): ?int
{
    $restaurant = panel_restaurant();
    return $restaurant ? (int) $restaurant['id'] : null;
}

/**
 * A restaurant is required for this screen, so stop if there is none.
 * Vendors always have one; an administrator is sent to pick.
 */
function require_panel_restaurant(): array
{
    $restaurant = panel_restaurant();

    if ($restaurant) {
        return $restaurant;
    }

    flash('Choose a restaurant to work on first.', 'info');
    redirect('admin/restaurants.php');
}

/** Let a platform administrator switch which restaurant they are viewing. */
function set_panel_restaurant(?int $restaurantId): void
{
    if ($restaurantId && restaurant_by_id($restaurantId)) {
        $_SESSION['panel_restaurant_id'] = $restaurantId;
    } else {
        unset($_SESSION['panel_restaurant_id']);
    }
}

/**
 * Deal with the restaurant switcher before anything else on the page.
 *
 * The switcher lives in the panel sidebar, so it posts back to whichever
 * screen is currently open. Every panel screen has its own POST handler
 * expecting its own actions, and without this those handlers see the
 * switch as a malformed request: the menu screen reports a missing dish,
 * the board reports a missing order. Calling this first means the switch
 * is dealt with and the page reloaded before any of that runs.
 *
 * It is safe to call more than once and on every request; it does
 * nothing unless the switch was actually submitted by an administrator.
 */
function handle_restaurant_switch(): void
{
    if (!is_post() || input('action') !== 'switch_restaurant') {
        return;
    }

    if (!has_role('admin')) {
        return;
    }

    verify_csrf();
    set_panel_restaurant((int) input('restaurant_id') ?: null);

    // Reload the same screen. REQUEST_URI is already an absolute path on
    // this host, so it is a safe Location without rebuilding the URL.
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('admin/dashboard.php')));
    exit;
}

/**
 * May the signed-in user manage this restaurant's data?
 *
 * Platform administrators may manage any. Everyone else is limited to
 * the restaurant on their own account. Call this before acting on any
 * id that arrived from a request.
 */
function can_manage_restaurant(?int $restaurantId): bool
{
    $user = current_user();

    if (!$user || !$restaurantId) {
        return false;
    }

    if ($user['role'] === 'admin') {
        return true;
    }

    return in_array($user['role'], ['vendor', 'staff'], true)
        && (int) $user['restaurant_id'] === (int) $restaurantId;
}

/**
 * Stop the request unless the user may manage this restaurant.
 * Used by every panel screen that acts on a record fetched by id.
 */
function require_restaurant_access(?int $restaurantId): void
{
    if (!can_manage_restaurant($restaurantId)) {
        http_response_code(403);
        flash('That record belongs to another restaurant.', 'error');
        redirect(home_for_role(current_role()));
    }
}

/**
 * Add "AND restaurant_id = ?" to a query when the user is scoped to one
 * restaurant, and nothing when a platform administrator is viewing all.
 *
 * @return array{0:string, 1:array} the clause fragment and its parameters
 */
function restaurant_filter(string $column = 'restaurant_id'): array
{
    $id = panel_restaurant_id();
    return $id ? [" AND $column = ?", [$id]] : ['', []];
}
