<?php
/**
 * Smart Eats - restaurant approval
 *
 * FR-23 approve or suspend a restaurant. Platform administrators only.
 *
 * This screen is the reason registration is a public form at all. Anyone
 * may apply to join the platform; nobody appears on it until a person
 * has looked at the application. A restaurant sits in one of three
 * states:
 *
 *   pending    registered, invisible to customers, owner can build menu
 *   approved   listed, searchable and able to take orders
 *   suspended  removed from the directory, existing orders still exist
 *
 * Suspending rather than deleting matters: a restaurant with order
 * history cannot be deleted without taking that history with it, and
 * that history is the evidence base for the processing-time analysis.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restaurants.php';

require_role('admin');

// The sidebar switcher posts back to this page, so it is dealt with
// before this screen's own POST handling sees an action it cannot place.
handle_restaurant_switch();

$page_title = 'Restaurants';

if (is_post()) {
    verify_csrf();

    $action = input('action');
    $id     = (int) input('id');
    $target = restaurant_by_id($id);

    if (!$target) {
        flash('That restaurant could not be found.', 'error');
        redirect('admin/restaurants.php');
    }

    switch ($action) {
        case 'approve':
            db_run(
                "UPDATE restaurants
                 SET approval_status = 'approved', approved_at = NOW()
                 WHERE id = ?",
                [$id]
            );
            flash($target['name'] . ' is now live and visible to customers.', 'success');
            break;

        case 'suspend':
            db_run(
                "UPDATE restaurants SET approval_status = 'suspended' WHERE id = ?",
                [$id]
            );
            flash(
                $target['name'] . ' has been suspended. It no longer appears to customers, '
                . 'and its order history is kept.',
                'success'
            );
            break;

        case 'reinstate':
            db_run(
                "UPDATE restaurants
                 SET approval_status = 'approved', approved_at = COALESCE(approved_at, NOW())
                 WHERE id = ?",
                [$id]
            );
            flash($target['name'] . ' has been reinstated.', 'success');
            break;

        case 'reject':
            // A pending application that is not going ahead goes to
            // suspended rather than being deleted, so the owner's account
            // and any menu they built are preserved if they appeal.
            db_run(
                "UPDATE restaurants SET approval_status = 'suspended' WHERE id = ?",
                [$id]
            );
            flash($target['name'] . ' has been declined.', 'success');
            break;

        case 'manage':
            set_panel_restaurant($id);
            flash('Now managing ' . $target['name'] . '.', 'info');
            redirect('admin/dashboard.php');
            break;
    }

    redirect('admin/restaurants.php');
}

$filter = input('status');

$sql = "SELECT r.*,
               u.full_name AS owner_name, u.email AS owner_email,
               (SELECT COUNT(*) FROM menu_items m
                 WHERE m.restaurant_id = r.id AND m.is_active = 1) AS dish_count,
               (SELECT COUNT(*) FROM orders o WHERE o.restaurant_id = r.id) AS order_count,
               (SELECT COALESCE(SUM(o.total), 0) FROM orders o
                 WHERE o.restaurant_id = r.id AND o.status <> 'cancelled') AS revenue
        FROM restaurants r
        LEFT JOIN users u ON u.id = r.owner_user_id";
$params = [];

if (in_array($filter, ['pending', 'approved', 'suspended'], true)) {
    $sql .= ' WHERE r.approval_status = ?';
    $params[] = $filter;
}

$sql .= " ORDER BY FIELD(r.approval_status, 'pending', 'approved', 'suspended'), r.name";

$restaurants = db_all($sql, $params);

$counts = db_one(
    "SELECT
       SUM(approval_status = 'pending')   AS pending,
       SUM(approval_status = 'approved')  AS approved,
       SUM(approval_status = 'suspended') AS suspended
     FROM restaurants"
);

include __DIR__ . '/../includes/panel_header.php';
?>

<div class="stats">
  <div class="stat<?= (int) $counts['pending'] ? ' stat--warn' : '' ?>">
    <p class="stat__label">Waiting for approval</p>
    <p class="stat__value"><?= (int) $counts['pending'] ?></p>
  </div>
  <div class="stat stat--ok">
    <p class="stat__label">Live</p>
    <p class="stat__value"><?= (int) $counts['approved'] ?></p>
  </div>
  <div class="stat">
    <p class="stat__label">Suspended</p>
    <p class="stat__value"><?= (int) $counts['suspended'] ?></p>
  </div>
</div>

<nav class="chips" aria-label="Filter" style="margin-bottom:1.5rem">
  <a class="chip<?= $filter === '' ? ' is-active' : '' ?>" href="<?= url('admin/restaurants.php') ?>">All</a>
  <a class="chip<?= $filter === 'pending' ? ' is-active' : '' ?>" href="<?= url('admin/restaurants.php?status=pending') ?>">
    Pending <span class="chip__count"><?= (int) $counts['pending'] ?></span>
  </a>
  <a class="chip<?= $filter === 'approved' ? ' is-active' : '' ?>" href="<?= url('admin/restaurants.php?status=approved') ?>">
    Live <span class="chip__count"><?= (int) $counts['approved'] ?></span>
  </a>
  <a class="chip<?= $filter === 'suspended' ? ' is-active' : '' ?>" href="<?= url('admin/restaurants.php?status=suspended') ?>">
    Suspended <span class="chip__count"><?= (int) $counts['suspended'] ?></span>
  </a>
</nav>

<?php if (!$restaurants): ?>

  <div class="empty">
    <p><strong>No restaurants in this view.</strong></p>
    <p>Restaurants appear here when an owner registers through
       <a href="<?= url('restaurant_register.php') ?>">the sign-up page</a>.</p>
  </div>

<?php else: ?>

  <div class="table-scroll">
    <table class="data">
      <thead>
        <tr>
          <th>Restaurant</th><th>Owner</th><th>Menu</th>
          <th>Orders</th><th>Status</th><th class="text-right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($restaurants as $restaurant): ?>
          <tr>
            <td>
              <strong><?= e($restaurant['name']) ?></strong><br>
              <span class="text-muted" style="font-size:.82rem">
                <?= e($restaurant['cuisine'] ?: 'No cuisine set') ?>
                <?php if ($restaurant['city']): ?>
                  &middot; <?= e($restaurant['city']) ?>
                <?php endif; ?>
                &middot; registered <?= e(pretty_date($restaurant['created_at'])) ?>
              </span>
            </td>
            <td>
              <?= e($restaurant['owner_name'] ?? 'No owner') ?><br>
              <span class="text-muted" style="font-size:.82rem"><?= e($restaurant['owner_email'] ?? '') ?></span>
            </td>
            <td><?= (int) $restaurant['dish_count'] ?> dishes</td>
            <td>
              <?= (int) $restaurant['order_count'] ?><br>
              <span class="text-muted" style="font-size:.82rem"><?= e(money($restaurant['revenue'])) ?></span>
            </td>
            <td>
              <?php
                $badge = match ($restaurant['approval_status']) {
                    'approved'  => 'completed',
                    'pending'   => 'pending',
                    default     => 'cancelled',
                };
              ?>
              <span class="badge badge--<?= $badge ?>">
                <?= e(ucfirst($restaurant['approval_status'])) ?>
              </span>
              <?php if ($restaurant['approval_status'] === 'approved'
                        && (int) $restaurant['is_accepting_orders'] !== 1): ?>
                <br><span class="badge badge--pending">Paused</span>
              <?php endif; ?>
            </td>
            <td class="text-right">
              <div class="row" style="justify-content:flex-end;gap:.35rem;flex-wrap:wrap">

                <a class="btn btn--sm btn--ghost"
                   href="<?= url('restaurant.php?r=' . urlencode($restaurant['slug'])) ?>">Preview</a>

                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="manage">
                  <input type="hidden" name="id" value="<?= (int) $restaurant['id'] ?>">
                  <button class="btn btn--sm btn--ghost" type="submit">Manage</button>
                </form>

                <?php if ($restaurant['approval_status'] === 'pending'): ?>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="id" value="<?= (int) $restaurant['id'] ?>">
                    <button class="btn btn--sm btn--accent" type="submit"
                            data-confirm="Make <?= e($restaurant['name']) ?> visible to customers?">
                      Approve
                    </button>
                  </form>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="id" value="<?= (int) $restaurant['id'] ?>">
                    <button class="btn btn--sm btn--ghost" type="submit"
                            data-confirm="Decline <?= e($restaurant['name']) ?>? The owner keeps their account.">
                      Decline
                    </button>
                  </form>

                <?php elseif ($restaurant['approval_status'] === 'approved'): ?>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="suspend">
                    <input type="hidden" name="id" value="<?= (int) $restaurant['id'] ?>">
                    <button class="btn btn--sm btn--ghost" type="submit"
                            data-confirm="Suspend <?= e($restaurant['name']) ?>? It disappears from the directory immediately.">
                      Suspend
                    </button>
                  </form>

                <?php else: ?>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reinstate">
                    <input type="hidden" name="id" value="<?= (int) $restaurant['id'] ?>">
                    <button class="btn btn--sm btn--accent" type="submit">Reinstate</button>
                  </form>
                <?php endif; ?>

              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="text-muted" style="font-size:.86rem;margin-top:1rem">
    A restaurant is suspended rather than deleted. Its orders, payments and
    status history are referenced by the reporting and cannot be removed
    without losing that record.
  </p>

<?php endif; ?>

<?php include __DIR__ . '/../includes/panel_footer.php'; ?>
