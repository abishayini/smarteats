<?php
/**
 * Smart Eats - live order board
 *
 * FR-09 real-time order dashboard, FR-10 update order status,
 * FR-11 new order notifications.
 *
 * Orders are grouped into the stages a kitchen actually works through.
 * Every status button is a plain form post handled here, so the board
 * keeps working if the polling script fails; dashboard.js only adds the
 * alert and the automatic refresh on top.
 *
 * PHASE 11 - the board is scoped. A staff member or owner sees their own
 * restaurant's orders and nothing else, because panel_restaurant_id()
 * returns the restaurant on their account and the query filters on it.
 * A platform administrator with no restaurant selected sees every
 * restaurant, with the name on each card; selecting one from the
 * switcher narrows the board to it.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_once __DIR__ . '/../includes/restaurants.php';

require_role('staff', 'vendor', 'admin');
require_active_restaurant();

// The sidebar switcher posts back to this page, so it is dealt with
// before this screen's own POST handling sees an action it cannot place.
handle_restaurant_switch();

$page_title  = 'Live orders';
$user        = current_user();
$scopeId     = panel_restaurant_id();
$scopeVendor = panel_restaurant();

if (is_post()) {
    verify_csrf();

    $orderId = (int) input('order_id');
    $status  = input('status');
    $order   = order_by_id($orderId);

    if (!$order) {
        flash('That order no longer exists.', 'error');

    // The order must belong to a restaurant this user may act for.
    // Without this check a staff account could move another
    // restaurant's order by posting its id.
    } elseif (!can_manage_order($order)) {
        http_response_code(403);
        flash('That order belongs to another restaurant.', 'error');

    } elseif (!in_array($status, next_statuses($order), true)) {
        // Guards against a stale board: two staff acting on the same
        // order would otherwise push it into an impossible state.
        flash(
            'Order ' . $order['order_number'] . ' has already moved on. The board has been refreshed.',
            'warning'
        );
    } else {
        set_order_status(
            $orderId,
            $status,
            (int) $user['id'],
            'Updated by ' . $user['full_name']
        );
        flash(
            'Order ' . $order['order_number'] . ' is now ' . strtolower(status_label($status)) . '.',
            'success'
        );
    }

    redirect('staff/dashboard.php');
}

$orders = active_orders($scopeId);

$columns = [
    'pending'   => ['title' => 'New',               'statuses' => ['pending']],
    'preparing' => ['title' => 'In the kitchen',    'statuses' => ['confirmed', 'preparing']],
    'ready'     => ['title' => 'Ready',             'statuses' => ['ready']],
    'delivery'  => ['title' => 'Out for delivery',  'statuses' => ['out_for_delivery']],
];

$grouped = array_fill_keys(array_keys($columns), []);
foreach ($orders as $order) {
    foreach ($columns as $key => $column) {
        if (in_array($order['status'], $column['statuses'], true)) {
            $grouped[$key][] = $order;
        }
    }
}

// Line items for every order on the board, fetched in one query rather
// than one query per card.
$lines = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    foreach (db_all(
        "SELECT order_id, item_name, quantity FROM order_items
         WHERE order_id IN ($placeholders) ORDER BY id",
        $ids
    ) as $line) {
        $lines[$line['order_id']][] = $line;
    }
}

$completedSql = "SELECT COUNT(*) FROM orders
                 WHERE status = 'completed' AND DATE(created_at) = CURDATE()";
$completedParams = [];
if ($scopeId) {
    $completedSql .= ' AND restaurant_id = ?';
    $completedParams[] = $scopeId;
}
$completedToday = (int) db_value($completedSql, $completedParams);

include __DIR__ . '/../includes/panel_header.php';
?>

<?php if ($scopeVendor && !restaurant_is_open($scopeVendor)): ?>
  <div class="alert alert--warning" role="status">
    <strong><?= e($scopeVendor['name']) ?> is not accepting new orders.</strong>
    Orders already on the board can still be worked through.
    <?php if (has_role('vendor', 'admin')): ?>
      <a href="<?= url('admin/settings.php') ?>">Change this in settings</a>.
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="alert alert--info no-print" id="new-order-alert" role="alert" hidden>
  <strong>New order.</strong> <span id="new-order-detail"></span>
  <button class="btn btn--sm btn--accent" type="button" id="refresh-now">Refresh now</button>
  <button class="btn btn--sm btn--ghost" type="button" id="dismiss-alert">Dismiss</button>
</div>

<div class="stats">
  <div class="stat stat--warn">
    <p class="stat__label">Waiting to be accepted</p>
    <p class="stat__value"><?= count($grouped['pending']) ?></p>
  </div>
  <div class="stat stat--accent">
    <p class="stat__label">In the kitchen</p>
    <p class="stat__value"><?= count($grouped['preparing']) ?></p>
  </div>
  <div class="stat">
    <p class="stat__label">Ready or on the way</p>
    <p class="stat__value"><?= count($grouped['ready']) + count($grouped['delivery']) ?></p>
  </div>
  <div class="stat stat--ok">
    <p class="stat__label">Completed today</p>
    <p class="stat__value"><?= $completedToday ?></p>
  </div>
</div>

<?php if (!$orders): ?>

  <div class="empty">
    <p><strong>Nothing on the board.</strong></p>
    <p>
      <?= $scopeVendor
          ? 'New orders for ' . e($scopeVendor['name']) . ' appear here as soon as customers place them.'
          : 'New orders appear here as soon as customers place them.' ?>
    </p>
  </div>

<?php else: ?>

  <div class="board">
    <?php foreach ($columns as $key => $column): ?>
      <section class="board__col">
        <h2 class="board__title">
          <?= e($column['title']) ?>
          <span class="board__count"><?= count($grouped[$key]) ?></span>
        </h2>

        <?php if (!$grouped[$key]): ?>
          <p class="board__empty">Nothing here.</p>
        <?php endif; ?>

        <?php foreach ($grouped[$key] as $order): ?>
          <?php $waiting = max(0, (int) floor((time() - strtotime($order['created_at'])) / 60)); ?>
          <article class="ticket-card<?= $waiting > 30 ? ' ticket-card--late' : '' ?>">

            <header class="ticket-card__head">
              <a class="ticket-card__ref"
                 href="<?= url('staff/order_view.php?id=' . (int) $order['id']) ?>">
                <?= e($order['order_number']) ?>
              </a>
              <span class="ticket-card__age"><?= $waiting ?> min</span>
            </header>

            <?php if (!$scopeId): ?>
              <?php // Only shown when an administrator is viewing every
                    // restaurant at once, where the name is essential. ?>
              <p class="ticket-card__vendor"><?= e($order['restaurant_name']) ?></p>
            <?php endif; ?>

            <p class="ticket-card__meta">
              <?= e($order['customer_name']) ?>
              &middot; <?= $order['order_type'] === 'pickup' ? 'Collection' : 'Delivery' ?>
              &middot; <?= e(money($order['total'])) ?>
            </p>

            <ul class="ticket-card__items">
              <?php foreach ($lines[$order['id']] ?? [] as $line): ?>
                <li><strong><?= (int) $line['quantity'] ?>&times;</strong> <?= e($line['item_name']) ?></li>
              <?php endforeach; ?>
            </ul>

            <?php if ($order['notes']): ?>
              <p class="ticket-card__note">Note: <?= e($order['notes']) ?></p>
            <?php endif; ?>

            <p class="ticket-card__pay">
              <span class="badge badge--<?= e($order['payment_status']) ?>">
                <?= $order['payment_method'] === 'cash' && $order['payment_status'] !== 'paid'
                    ? 'Cash on ' . ($order['order_type'] === 'pickup' ? 'collection' : 'delivery')
                    : e(ucfirst($order['payment_status'])) ?>
              </span>
            </p>

            <div class="ticket-card__actions">
              <?php foreach (next_statuses($order) as $status): ?>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                  <input type="hidden" name="status" value="<?= e($status) ?>">
                  <button class="btn btn--sm <?= $status === 'cancelled' ? 'btn--ghost' : 'btn--accent' ?>"
                          type="submit"
                          <?= $status === 'cancelled'
                              ? 'data-confirm="Cancel order ' . e($order['order_number']) . '?"'
                              : '' ?>>
                    <?= e(transition_label($status, $order)) ?>
                  </button>
                </form>
              <?php endforeach; ?>
            </div>

          </article>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  </div>

<?php endif; ?>

<script>
  window.SMARTEATS_BOARD = {
    endpoint: <?= json_encode(url('api/staff_orders.php')) ?>,
    stamp:    <?= json_encode(board_stamp($scopeId)) ?>
  };
</script>
<script src="<?= ASSETS_URL ?>/js/dashboard.js"></script>

<?php include __DIR__ . '/../includes/panel_footer.php'; ?>
