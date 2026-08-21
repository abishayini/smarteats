<?php
/**
 * Smart Eats - order history
 *
 * FR-16 view order history.
 *
 * Filtering by status, type and date range, with the summary figures a
 * small restaurant would actually check at the end of service.
 *
 * A restaurant owner sees their own orders only. A platform
 * administrator with no restaurant selected sees every order, with a
 * restaurant column and a restaurant filter; selecting one from the
 * switcher narrows the whole report to it.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_once __DIR__ . '/../includes/restaurants.php';

require_role('admin', 'vendor');
require_active_restaurant();

// The sidebar switcher posts back to this page, so it is dealt with
// before this screen's own POST handling sees an action it cannot place.
handle_restaurant_switch();

$page_title = 'Order history';

$scopeId    = panel_restaurant_id();
$isPlatform = has_role('admin') && !$scopeId;

$status   = input('status');
$type     = input('type');
$from     = input('from');
$to       = input('to');
$search   = input('q');
$page     = max(1, (int) input('page', '1'));
$perPage  = 25;

$where  = [];
$params = [];

// The scope is applied first and is not user-editable, so no filter
// below can widen it.
if ($scopeId) {
    $where[] = 'o.restaurant_id = ?';
    $params[] = $scopeId;
}

if ($status !== '' && isset($ORDER_STATUSES[$status])) {
    $where[] = 'o.status = ?';
    $params[] = $status;
}

if (in_array($type, ['delivery', 'pickup'], true)) {
    $where[] = 'o.order_type = ?';
    $params[] = $type;
}

if ($from !== '') {
    $where[] = 'DATE(o.created_at) >= ?';
    $params[] = $from;
}

if ($to !== '') {
    $where[] = 'DATE(o.created_at) <= ?';
    $params[] = $to;
}

if ($search !== '') {
    $where[] = '(o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

$clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$joined = "FROM orders o JOIN restaurants r ON r.id = o.restaurant_id $clause";

$total = (int) db_value("SELECT COUNT(*) $joined", $params);
$pages = max(1, (int) ceil($total / $perPage));
$page  = min($page, $pages);

$summary = db_one(
    "SELECT
        COUNT(*) AS orders,
        COALESCE(SUM(CASE WHEN o.status <> 'cancelled' THEN o.total END), 0) AS revenue,
        COALESCE(AVG(CASE WHEN o.status <> 'cancelled' THEN o.total END), 0) AS average,
        SUM(o.status = 'cancelled') AS cancelled
     $joined",
    $params
);

$orders = db_all(
    "SELECT o.*, r.name AS restaurant_name
     $joined
     ORDER BY o.created_at DESC
     LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);

/** Rebuild the current query string with one value changed. */
function history_url(array $changes = []): string
{
    $query = array_merge($_GET, $changes);
    $query = array_filter($query, fn($v) => $v !== '' && $v !== null);
    return url('admin/orders.php') . ($query ? '?' . http_build_query($query) : '');
}

include __DIR__ . '/../includes/panel_header.php';
?>

<?php if ($isPlatform): ?>
  <p class="text-muted" style="margin-bottom:1.25rem">
    Every order across the platform. Choose a restaurant in the switcher to
    narrow this to one business.
  </p>
<?php endif; ?>

<div class="stats">
  <div class="stat">
    <p class="stat__label">Orders</p>
    <p class="stat__value"><?= (int) $summary['orders'] ?></p>
  </div>
  <div class="stat stat--ok">
    <p class="stat__label">Revenue</p>
    <p class="stat__value" style="font-size:1.5rem"><?= e(money($summary['revenue'])) ?></p>
  </div>
  <div class="stat stat--accent">
    <p class="stat__label">Average order</p>
    <p class="stat__value" style="font-size:1.5rem"><?= e(money($summary['average'])) ?></p>
  </div>
  <div class="stat stat--warn">
    <p class="stat__label">Cancelled</p>
    <p class="stat__value"><?= (int) $summary['cancelled'] ?></p>
  </div>
</div>

<form class="card" method="get" style="margin-bottom:1.5rem">
  <div class="grid grid--3" style="gap:1rem">
    <div class="field" style="margin:0">
      <label for="q">Search</label>
      <input type="search" id="q" name="q" value="<?= e($search) ?>"
             placeholder="Reference, name or phone">
    </div>

    <div class="field" style="margin:0">
      <label for="status">Status</label>
      <select id="status" name="status">
        <option value="">Any status</option>
        <?php foreach ($ORDER_STATUSES as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field" style="margin:0">
      <label for="type">Type</label>
      <select id="type" name="type">
        <option value="">Any type</option>
        <option value="delivery" <?= $type === 'delivery' ? 'selected' : '' ?>>Delivery</option>
        <option value="pickup" <?= $type === 'pickup' ? 'selected' : '' ?>>Collection</option>
      </select>
    </div>

    <div class="field" style="margin:0">
      <label for="from">From</label>
      <input type="date" id="from" name="from" value="<?= e($from) ?>">
    </div>

    <div class="field" style="margin:0">
      <label for="to">To</label>
      <input type="date" id="to" name="to" value="<?= e($to) ?>">
    </div>

    <div class="field" style="margin:0;display:flex;align-items:flex-end;gap:.5rem">
      <button class="btn" type="submit">Apply</button>
      <a class="btn btn--ghost" href="<?= url('admin/orders.php') ?>">Clear</a>
    </div>
  </div>
</form>

<?php if (!$orders): ?>

  <div class="empty">
    <p><strong>No orders match those filters.</strong></p>
    <p><a href="<?= url('admin/orders.php') ?>">Clear the filters</a> to see everything.</p>
  </div>

<?php else: ?>

  <div class="table-scroll">
    <table class="data">
      <thead>
        <tr>
          <th>Reference</th>
          <?php if ($isPlatform): ?><th>Restaurant</th><?php endif; ?>
          <th>Placed</th><th>Customer</th><th>Type</th>
          <th>Status</th><th>Payment</th><th class="text-right">Total</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $order): ?>
          <tr>
            <td><strong><?= e($order['order_number']) ?></strong></td>
            <?php if ($isPlatform): ?>
              <td><?= e($order['restaurant_name']) ?></td>
            <?php endif; ?>
            <td><?= e(pretty_date($order['created_at'])) ?></td>
            <td>
              <?= e($order['customer_name']) ?><br>
              <span class="text-muted" style="font-size:.82rem"><?= e($order['customer_phone']) ?></span>
            </td>
            <td><?= $order['order_type'] === 'pickup' ? 'Collection' : 'Delivery' ?></td>
            <td>
              <span class="badge badge--<?= e($order['status']) ?>"><?= e(status_label($order['status'])) ?></span>
            </td>
            <td>
              <span class="badge badge--<?= e($order['payment_status']) ?>">
                <?= e(ucfirst($order['payment_status'])) ?>
              </span>
              <?php if ($order['payment_method'] === 'cash'): ?>
                <br><span class="text-muted" style="font-size:.78rem">Cash</span>
              <?php endif; ?>
            </td>
            <td class="text-right"><?= e(money($order['total'])) ?></td>
            <td class="text-right">
              <a class="btn btn--sm btn--ghost"
                 href="<?= url('staff/order_view.php?id=' . (int) $order['id']) ?>">View</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
    <div class="row row--between" style="margin-top:1.25rem">
      <span class="text-muted" style="font-size:.9rem">
        Page <?= $page ?> of <?= $pages ?> &middot; <?= $total ?> orders
      </span>
      <div class="row" style="gap:.4rem">
        <?php if ($page > 1): ?>
          <a class="btn btn--sm btn--ghost" href="<?= e(history_url(['page' => $page - 1])) ?>">Previous</a>
        <?php endif; ?>
        <?php if ($page < $pages): ?>
          <a class="btn btn--sm btn--ghost" href="<?= e(history_url(['page' => $page + 1])) ?>">Next</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/../includes/panel_footer.php'; ?>
