<?php
/**
 * Smart Eats - panel overview
 *
 * One screen serving two audiences. A restaurant owner sees their own
 * trading position; a platform administrator with no restaurant selected
 * sees the platform as a whole, and selecting a restaurant from the
 * switcher turns this into that restaurant's overview.
 *
 * Rather than two files kept roughly in step, the difference is a single
 * scope id applied to each query.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_once __DIR__ . '/../includes/restaurants.php';

require_role('admin', 'vendor');
require_active_restaurant();

// The sidebar switcher posts back to this page, so it is dealt with
// before this screen's own POST handling sees an action it cannot place.
handle_restaurant_switch();

$scopeId     = panel_restaurant_id();
$scopeVendor = panel_restaurant();
$isPlatform  = has_role('admin') && !$scopeId;

$page_title = $isPlatform ? 'Platform overview' : 'Overview';

/** Apply the restaurant scope to a WHERE clause. */
function scoped(string $sql, array $params, ?int $scopeId, string $column = 'restaurant_id'): array
{
    if ($scopeId) {
        $sql .= " AND $column = ?";
        $params[] = $scopeId;
    }
    return [$sql, $params];
}

[$sql, $params] = scoped(
    'SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()',
    [], $scopeId
);
$ordersToday = (int) db_value($sql, $params);

[$sql, $params] = scoped(
    "SELECT COALESCE(SUM(total), 0) FROM orders
     WHERE DATE(created_at) = CURDATE()
       AND status <> 'cancelled' AND payment_status = 'paid'",
    [], $scopeId
);
$revenueToday = (float) db_value($sql, $params);

[$sql, $params] = scoped(
    "SELECT COUNT(*) FROM orders
     WHERE status IN ('pending','confirmed','preparing','ready','out_for_delivery')",
    [], $scopeId
);
$openOrders = (int) db_value($sql, $params);

[$sql, $params] = scoped(
    'SELECT COUNT(*) FROM menu_items WHERE is_active = 1 AND is_available = 1',
    [], $scopeId
);
$liveDishes = (int) db_value($sql, $params);

// Dishes marked unavailable today, which is the list a kitchen checks
// before service.
[$sql, $params] = scoped(
    'SELECT m.name, c.name AS category
     FROM menu_items m
     JOIN categories c ON c.id = m.category_id
     WHERE m.is_active = 1 AND m.is_available = 0',
    [], $scopeId, 'm.restaurant_id'
);
$unavailable = db_all($sql . ' ORDER BY c.sort_order, m.name', $params);

// Platform-only figures.
$restaurantCounts = $isPlatform
    ? db_one(
        "SELECT
           SUM(approval_status = 'approved')  AS approved,
           SUM(approval_status = 'pending')   AS pending,
           SUM(approval_status = 'suspended') AS suspended
         FROM restaurants"
      )
    : null;

$busiest = $isPlatform
    ? db_all(
        "SELECT r.name, r.slug, COUNT(o.id) AS orders,
                COALESCE(SUM(CASE WHEN o.status <> 'cancelled' THEN o.total END), 0) AS revenue
         FROM restaurants r
         LEFT JOIN orders o ON o.restaurant_id = r.id
                           AND o.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
         WHERE r.approval_status = 'approved'
         GROUP BY r.id, r.name, r.slug
         ORDER BY orders DESC, r.name
         LIMIT 6"
      )
    : [];

include __DIR__ . '/../includes/panel_header.php';
?>

<?php if ($scopeVendor && $scopeVendor['approval_status'] === 'pending'): ?>
  <div class="alert alert--warning" role="status">
    <strong><?= e($scopeVendor['name']) ?> is waiting for approval.</strong>
    Customers cannot see it yet. Build your menu now and it appears on the
    directory as soon as Smart Eats approves it.
  </div>
<?php elseif ($scopeVendor && $scopeVendor['approval_status'] === 'suspended'): ?>
  <div class="alert alert--error" role="alert">
    <strong><?= e($scopeVendor['name']) ?> is suspended.</strong>
    Contact Smart Eats support at <?= e(setting('platform_email')) ?>.
  </div>
<?php elseif ($scopeVendor && (int) $scopeVendor['is_accepting_orders'] !== 1): ?>
  <div class="alert alert--warning" role="status">
    <strong>Online ordering is paused.</strong>
    Customers can browse your menu but cannot order.
    <a href="<?= url('admin/settings.php') ?>">Start accepting orders</a>.
  </div>
<?php endif; ?>

<div class="stats">
  <div class="stat stat--ok">
    <p class="stat__label">Paid today</p>
    <p class="stat__value"><?= e(money($revenueToday)) ?></p>
  </div>
  <div class="stat stat--accent">
    <p class="stat__label">Orders today</p>
    <p class="stat__value"><?= $ordersToday ?></p>
  </div>
  <div class="stat<?= $openOrders ? ' stat--warn' : '' ?>">
    <p class="stat__label">Open now</p>
    <p class="stat__value"><?= $openOrders ?></p>
  </div>
  <div class="stat">
    <p class="stat__label">Dishes available</p>
    <p class="stat__value"><?= $liveDishes ?></p>
  </div>
</div>

<?php if ($isPlatform): ?>
  <div class="stats">
    <div class="stat stat--ok">
      <p class="stat__label">Approved restaurants</p>
      <p class="stat__value"><?= (int) $restaurantCounts['approved'] ?></p>
    </div>
    <div class="stat<?= (int) $restaurantCounts['pending'] ? ' stat--warn' : '' ?>">
      <p class="stat__label">Waiting for approval</p>
      <p class="stat__value"><?= (int) $restaurantCounts['pending'] ?></p>
    </div>
    <div class="stat">
      <p class="stat__label">Suspended</p>
      <p class="stat__value"><?= (int) $restaurantCounts['suspended'] ?></p>
    </div>
    <div class="stat">
      <p class="stat__label">Registered customers</p>
      <p class="stat__value"><?= (int) db_value("SELECT COUNT(*) FROM users WHERE role = 'customer'") ?></p>
    </div>
  </div>
<?php endif; ?>

<div class="grid grid--2">

  <?php if ($isPlatform): ?>

    <div class="card">
      <h2 class="card__title">Busiest this week</h2>
      <?php if (!$busiest): ?>
        <p class="text-muted">No approved restaurants yet.</p>
      <?php else: ?>
        <table class="data" style="box-shadow:none">
          <thead>
            <tr><th>Restaurant</th><th>Orders</th><th class="text-right">Revenue</th></tr>
          </thead>
          <tbody>
            <?php foreach ($busiest as $row): ?>
              <tr>
                <td><a href="<?= url('restaurant.php?r=' . urlencode($row['slug'])) ?>"><?= e($row['name']) ?></a></td>
                <td><?= (int) $row['orders'] ?></td>
                <td class="text-right"><?= e(money($row['revenue'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  <?php else: ?>

    <div class="card">
      <h2 class="card__title">Currently unavailable</h2>
      <?php if (!$unavailable): ?>
        <p class="text-muted">Every active dish is available to order.</p>
      <?php else: ?>
        <ul class="stack" style="margin:0;padding-left:1.1rem">
          <?php foreach ($unavailable as $item): ?>
            <li><?= e($item['name']) ?> <span class="text-muted">&middot; <?= e($item['category']) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <p class="text-muted" style="font-size:.86rem;margin-top:1rem">
          Customers see these greyed out and cannot order them.
          <a href="<?= url('admin/menu.php') ?>">Change availability</a>.
        </p>
      <?php endif; ?>
    </div>

  <?php endif; ?>

  <div class="card">
    <h2 class="card__title">Quick actions</h2>
    <div class="row" style="gap:.5rem;flex-wrap:wrap">
      <a class="btn btn--sm btn--accent" href="<?= url('admin/item_form.php') ?>">Add a dish</a>
      <a class="btn btn--sm btn--ghost" href="<?= url('admin/menu.php') ?>">Menu items</a>
      <a class="btn btn--sm btn--ghost" href="<?= url('admin/categories.php') ?>">Categories</a>
      <a class="btn btn--sm btn--ghost" href="<?= url('staff/dashboard.php') ?>">Live orders</a>
      <a class="btn btn--sm btn--ghost" href="<?= url('admin/orders.php') ?>">Order history</a>
      <a class="btn btn--sm btn--ghost" href="<?= url('admin/users.php') ?>">Staff</a>
      <a class="btn btn--sm btn--ghost" href="<?= url('admin/settings.php') ?>">Settings</a>
      <?php if (has_role('admin')): ?>
        <a class="btn btn--sm btn--ghost" href="<?= url('admin/restaurants.php') ?>">Restaurants</a>
      <?php endif; ?>
    </div>

    <?php if ($scopeVendor): ?>
      <p class="text-muted" style="font-size:.86rem;margin-top:1.25rem">
        Your public page:
        <a href="<?= e(restaurant_url($scopeVendor)) ?>"><?= e(restaurant_url($scopeVendor)) ?></a>
      </p>
    <?php elseif ($isPlatform): ?>
      <p class="text-muted" style="font-size:.86rem;margin-top:1.25rem">
        You are viewing every restaurant. Choose one from the switcher on the
        left to manage its menu, staff or settings.
      </p>
    <?php endif; ?>
  </div>

</div>

<?php include __DIR__ . '/../includes/panel_footer.php'; ?>
