<?php
/**
 * Smart Eats - my orders
 *
 * A signed-in customer's order history, with one-tap reordering and a
 * prompt to review anything they have received.
 *
 * Each order shows the restaurant that cooked it, because a customer
 * with a month of orders across four kitchens cannot tell them apart by
 * date and total alone. Reordering may replace a basket held with a
 * different restaurant, so that outcome is stated rather than assumed.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/restaurants.php';

require_login();

$page_title = 'My orders';
$user       = current_user();

if (is_post()) {
    verify_csrf();

    $orderId = (int) input('order_id');
    $order   = order_by_id($orderId);

    // The order must belong to this customer, not merely exist.
    if (!$order || (int) $order['user_id'] !== (int) $user['id']) {
        flash('That order could not be found.', 'error');
        redirect('my_orders.php');
    }

    if (input('action') === 'reorder') {
        $result = reorder_into_basket($orderId);

        if ($result['replaced']) {
            flash('Your previous basket was from another restaurant and has been replaced.', 'info');
        }

        foreach ($result['skipped'] as $note) {
            flash($note . '.', 'warning');
        }

        if ($result['added']) {
            flash(
                $result['added'] . ' item' . ($result['added'] === 1 ? '' : 's')
                . ' added to your basket.',
                'success'
            );
            redirect('cart.php');
        }

        flash('Nothing from that order is available at the moment.', 'error');
    }

    redirect('my_orders.php');
}

$page    = max(1, (int) input('page', '1'));
$perPage = 10;

$total = (int) db_value('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$user['id']]);
$pages = max(1, (int) ceil($total / $perPage));
$page  = min($page, $pages);

$orders = db_all(
    "SELECT o.*, r.name AS restaurant_name, r.slug AS restaurant_slug,
            r.approval_status AS restaurant_status
     FROM orders o
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.user_id = ?
     ORDER BY o.created_at DESC
     LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    [$user['id']]
);

// Line items for every order on this page, in one query.
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

$spent = (float) db_value(
    "SELECT COALESCE(SUM(total), 0) FROM orders
     WHERE user_id = ? AND status <> 'cancelled'",
    [$user['id']]
);

$vendorCount = (int) db_value(
    'SELECT COUNT(DISTINCT restaurant_id) FROM orders WHERE user_id = ?',
    [$user['id']]
);

include __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap" style="max-width:860px">

    <h1>My orders</h1>

    <?php if ($total): ?>
      <p class="text-muted">
        <?= $total ?> order<?= $total === 1 ? '' : 's' ?>
        from <?= $vendorCount ?> restaurant<?= $vendorCount === 1 ? '' : 's' ?>
        &middot; <?= e(money($spent)) ?> spent
      </p>
    <?php endif; ?>

    <?php if (!$orders): ?>

      <div class="empty">
        <p><strong>You have not ordered yet.</strong></p>
        <p>Once you do, your orders appear here and you can repeat one in a tap.</p>
        <p style="margin-top:1.25rem">
          <a class="btn btn--accent" href="<?= url('restaurants.php') ?>">Browse restaurants</a>
        </p>
      </div>

    <?php else: ?>

      <?php foreach ($orders as $order): ?>
        <?php
          $reviewable     = order_is_reviewable($order);
          $pendingReviews = $reviewable ? pending_review_count((int) $order['id']) : 0;
          $pendingVendor  = $reviewable && restaurant_review_pending((int) $order['id']);
          $canReview      = $pendingReviews > 0 || $pendingVendor;
          $vendorGone = $order['restaurant_status'] !== 'approved';
        ?>
        <article class="past-order">

          <div class="past-order__head">
            <div>
              <h2 class="past-order__ref">
                <?php if (!$vendorGone): ?>
                  <a href="<?= url('restaurant.php?r=' . urlencode($order['restaurant_slug'])) ?>">
                    <?= e($order['restaurant_name']) ?>
                  </a>
                <?php else: ?>
                  <?= e($order['restaurant_name']) ?>
                <?php endif; ?>
              </h2>
              <p class="text-muted" style="margin:0;font-size:.88rem">
                <?= e($order['order_number']) ?>
                &middot; <?= e(pretty_date($order['created_at'])) ?>
                &middot; <?= $order['order_type'] === 'pickup' ? 'Collection' : 'Delivery' ?>
              </p>
            </div>
            <div class="text-right">
              <span class="badge badge--<?= e($order['status']) ?>">
                <?= e(status_label($order['status'])) ?>
              </span>
              <p class="past-order__total"><?= e(money($order['total'])) ?></p>
            </div>
          </div>

          <ul class="past-order__items">
            <?php foreach ($lines[$order['id']] ?? [] as $line): ?>
              <li><?= (int) $line['quantity'] ?>&times; <?= e($line['item_name']) ?></li>
            <?php endforeach; ?>
          </ul>

          <?php if ($canReview): ?>
            <p class="past-order__prompt">
              How was it?
              <?php if ($pendingVendor && $pendingReviews): ?>
                You can rate <?= e($order['restaurant_name']) ?> and
                <?= $pendingReviews ?> dish<?= $pendingReviews === 1 ? '' : 'es' ?>.
              <?php elseif ($pendingVendor): ?>
                You can still rate <?= e($order['restaurant_name']) ?>.
              <?php else: ?>
                <?= $pendingReviews ?> dish<?= $pendingReviews === 1 ? '' : 'es' ?>
                from this order can still be rated.
              <?php endif; ?>
            </p>
          <?php endif; ?>

          <div class="past-order__actions">
            <?php if (!$vendorGone): ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reorder">
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                <button class="btn btn--sm btn--accent" type="submit"
                        <?= cart_restaurant_id() && cart_restaurant_id() !== (int) $order['restaurant_id']
                            ? 'data-confirm="Your current basket is from another restaurant and will be replaced. Continue?"'
                            : '' ?>>
                  Order this again
                </button>
              </form>
            <?php else: ?>
              <span class="badge badge--cancelled">Restaurant unavailable</span>
            <?php endif; ?>

            <?php if ($canReview): ?>
              <a class="btn btn--sm btn--ghost"
                 href="<?= url('review.php?order=' . urlencode($order['order_number'])) ?>">
                Leave a review
              </a>
            <?php endif; ?>

            <a class="btn btn--sm btn--ghost"
               href="<?= url('track.php?order=' . urlencode($order['order_number'])) ?>">
              <?= in_array($order['status'], ['completed', 'cancelled'], true) ? 'Details' : 'Track' ?>
            </a>
          </div>

        </article>
      <?php endforeach; ?>

      <?php if ($pages > 1): ?>
        <div class="row row--between" style="margin-top:1.5rem">
          <span class="text-muted" style="font-size:.9rem">Page <?= $page ?> of <?= $pages ?></span>
          <div class="row" style="gap:.4rem">
            <?php if ($page > 1): ?>
              <a class="btn btn--sm btn--ghost" href="<?= url('my_orders.php?page=' . ($page - 1)) ?>">Newer</a>
            <?php endif; ?>
            <?php if ($page < $pages): ?>
              <a class="btn btn--sm btn--ghost" href="<?= url('my_orders.php?page=' . ($page + 1)) ?>">Older</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
