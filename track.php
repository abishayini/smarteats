<?php
/**
 * Smart Eats - track an order
 *
 * FR-06 live order tracking.
 *
 * A customer who already owns the order in this session, or who is
 * signed in as its owner, sees it straight away. Anyone else must
 * supply the order reference together with the phone number on the
 * order, so a reference guessed or overheard is not enough on its own.
 *
 * PHASE 11 - the tracker names the restaurant. Before this, a customer
 * looking at the tracking page was told the food was being prepared but
 * not by whom, which on a platform of several kitchens tells them very
 * little. The restaurant's name, address and telephone number are now
 * part of the result, so the page is useful without the customer having
 * to remember where they ordered from.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/restaurants.php';

$page_title = 'Track your order';

$reference = input('order');
$phone     = input('phone');
$order     = null;
$error     = '';

if ($reference !== '') {
    $candidate = order_by_number(strtoupper($reference));

    if (!$candidate) {
        $error = 'No order matches that reference.';
    } elseif (can_view_order($candidate)) {
        $order = $candidate;
    } elseif ($phone !== '') {
        // Compare digits only, so spacing and formatting do not matter.
        $given  = preg_replace('/\D+/', '', $phone);
        $stored = preg_replace('/\D+/', '', $candidate['customer_phone']);

        if ($given !== '' && hash_equals($stored, $given)) {
            $order = $candidate;
            remember_order((int) $candidate['id']);
        } else {
            $error = 'That phone number does not match the order.';
        }
    } else {
        $error = 'Enter the phone number used on the order to view it.';
    }
}

$restaurant = $order ? restaurant_by_id((int) $order['restaurant_id']) : null;

include __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap" style="max-width:760px">

    <h1>Track your order</h1>

    <?php if (!$order): ?>

      <p class="text-muted">
        Enter the reference from your confirmation, for example SE-260804-4831.
        It works whichever restaurant you ordered from.
      </p>

      <?php if ($error): ?>
        <div class="alert alert--error" role="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <form class="card" method="get" action="<?= url('track.php') ?>" novalidate>
        <div class="field">
          <label for="order">Order reference</label>
          <input type="text" id="order" name="order" value="<?= e($reference) ?>"
                 placeholder="SE-260804-4831" required autofocus>
        </div>

        <div class="field">
          <label for="phone">Phone number on the order</label>
          <input type="tel" id="phone" name="phone" value="<?= e($phone) ?>"
                 autocomplete="tel">
          <span class="field__hint">
            Not needed if you placed the order on this device.
          </span>
        </div>

        <button class="btn btn--accent" type="submit">Find my order</button>
      </form>

    <?php else: ?>

      <?php $lines = order_lines((int) $order['id']); ?>

      <p class="text-muted">
        Reference <strong><?= e($order['order_number']) ?></strong>
        &middot; placed <?= e(pretty_date($order['created_at'])) ?>
      </p>

      <?php // The restaurant, first and prominently. ?>
      <div class="track-vendor">
        <?php $logo = restaurant_logo_url($restaurant['logo']); ?>
        <?php if ($logo): ?>
          <img class="track-vendor__logo" src="<?= e($logo) ?>" alt="">
        <?php else: ?>
          <span class="track-vendor__logo track-vendor__logo--text" aria-hidden="true">
            <?= e(restaurant_monogram($restaurant['name'])) ?>
          </span>
        <?php endif; ?>

        <div>
          <p class="track-vendor__label">Your order is with</p>
          <h2 class="track-vendor__name">
            <a href="<?= e(restaurant_url($restaurant)) ?>"><?= e($restaurant['name']) ?></a>
          </h2>
          <p class="text-muted" style="margin:.2rem 0 0;font-size:.9rem">
            <?= e($restaurant['address']) ?>
            <?php if ($restaurant['phone']): ?>
              &middot; <a href="tel:<?= e(preg_replace('/\s+/', '', $restaurant['phone'])) ?>"><?= e($restaurant['phone']) ?></a>
            <?php endif; ?>
          </p>
        </div>
      </div>

      <div class="card" style="margin-bottom:1.5rem"
           data-track-order="<?= e($order['order_number']) ?>"
           data-track-status="<?= e($order['status']) ?>">
        <h2 class="card__title">
          <?= $order['status'] === 'cancelled'
              ? 'This order was cancelled'
              : e(status_label($order['status'])) ?>
        </h2>

        <ol class="timeline">
          <?php foreach (order_timeline($order) as $step): ?>
            <li class="timeline__step timeline__step--<?= e($step['state']) ?>">
              <span class="timeline__dot" aria-hidden="true"></span>
              <span class="timeline__label"><?= e($step['label']) ?></span>
            </li>
          <?php endforeach; ?>
        </ol>

        <p class="text-muted" style="font-size:.88rem;margin:1rem 0 0">
          <?= $order['order_type'] === 'pickup'
              ? 'Collection from ' . e($restaurant['name']) . ', ' . e($restaurant['address'])
              : 'Delivering to ' . e($order['delivery_address']) ?>
        </p>
      </div>

      <div class="card">
        <h2 class="card__title">What you ordered</h2>
        <ul class="summary-list">
          <?php foreach ($lines as $line): ?>
            <li>
              <span class="summary-list__qty"><?= (int) $line['quantity'] ?>&times;</span>
              <span class="summary-list__name"><?= e($line['item_name']) ?></span>
              <span class="summary-list__price"><?= e(money($line['line_total'])) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <dl class="totals">
          <div class="totals__row totals__row--grand">
            <dt>Total</dt><dd><?= e(money($order['total'])) ?></dd>
          </div>
        </dl>
      </div>

      <p style="margin-top:1.5rem">
        Something wrong? Call <?= e($restaurant['name']) ?> on
        <?= e($restaurant['phone'] ?: setting('platform_phone')) ?> with your reference.
      </p>

    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
