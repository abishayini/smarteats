<?php
/**
 * Smart Eats - order confirmation
 *
 * Shown after checkout. When a customer returns from Stripe, the
 * PaymentIntent is read back from the API before the order is marked
 * paid. The status is never taken from the query string, because that
 * would let anyone confirm an order by editing the URL.
 *
 * The restaurant is named throughout. On a platform a customer may have
 * two orders open with two kitchens, so a confirmation that only says
 * "your order is in" is not enough.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/restaurants.php';
require_once __DIR__ . '/includes/stripe.php';

$order = order_by_number(input('order'));

if (!$order || !can_view_order($order)) {
    http_response_code(404);
    $page_title = 'Order not found';
    include __DIR__ . '/includes/header.php';
    echo '<section class="section"><div class="wrap"><div class="empty">'
       . '<p><strong>That order could not be found.</strong></p>'
       . '<p><a href="' . url('track.php') . '">Track an order</a> using its reference and phone number.</p>'
       . '</div></div></section>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$paymentNotice = '';

// Returning from Stripe: confirm the real status with the API.
$returnedIntent = input('payment_intent');

if ($returnedIntent !== '' && $order['payment_status'] !== 'paid' && stripe_configured()) {
    $result = stripe_retrieve_payment_intent($returnedIntent);

    if ($result['ok']) {
        $intent = $result['data'];
        $amount = ($intent['amount_received'] ?? $intent['amount'] ?? 0) / 100;

        // The intent must belong to this order.
        if ((int) ($intent['metadata']['order_id'] ?? 0) !== (int) $order['id']) {
            $paymentNotice = 'That payment does not belong to this order.';
        } elseif (($intent['status'] ?? '') === 'succeeded') {
            mark_order_paid((int) $order['id'], $intent['id'], $amount, strtoupper($intent['currency'] ?? 'GBP'));
            $order = order_by_id((int) $order['id']);
        } elseif (($intent['status'] ?? '') === 'processing') {
            $paymentNotice = 'Your payment is still being processed. This page will show the '
                           . 'confirmed status once the bank responds.';
        } else {
            mark_payment_failed(
                (int) $order['id'],
                $intent['id'],
                (float) $order['total'],
                $intent['last_payment_error']['message'] ?? ''
            );
            $order = order_by_id((int) $order['id']);
            $paymentNotice = 'The payment was not completed. You have not been charged.';
        }
    } else {
        $paymentNotice = 'The payment could not be verified. If money has left your account, '
                       . 'contact the restaurant with your order reference.';
    }
}

$restaurant   = restaurant_by_id((int) $order['restaurant_id']);
$page_title   = 'Order ' . $order['order_number'];
$lines        = order_lines((int) $order['id']);
$paid         = $order['payment_status'] === 'paid';
$isCash       = $order['payment_method'] === 'cash';
$needsPayment = !$paid && !$isCash && $order['status'] !== 'cancelled';

include __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap" style="max-width:820px">

    <?php if ($paid || $isCash): ?>
      <div class="confirm-head">
        <span class="confirm-head__tick" aria-hidden="true">&check;</span>
        <div>
          <h1>Thank you, <?= e($restaurant['name'] ?? 'the restaurant') ?> has your order.</h1>
          <p class="text-muted">
            Reference <strong><?= e($order['order_number']) ?></strong>
            &middot; placed <?= e(pretty_date($order['created_at'])) ?>
          </p>
        </div>
      </div>
    <?php else: ?>
      <h1>Order <?= e($order['order_number']) ?></h1>
      <p class="text-muted">With <?= e($restaurant['name'] ?? 'the restaurant') ?></p>
    <?php endif; ?>

    <?php if ($paymentNotice): ?>
      <div class="alert alert--warning" role="alert"><?= e($paymentNotice) ?></div>
    <?php endif; ?>

    <?php if ($needsPayment): ?>
      <div class="alert alert--warning" role="alert">
        This order has not been paid for yet. It will not reach the kitchen
        until payment is complete.
      </div>
      <p>
        <a class="btn btn--accent" href="<?= url('payment.php?order=' . urlencode($order['order_number'])) ?>">
          Complete payment
        </a>
      </p>
    <?php endif; ?>

    <div class="card" style="margin-bottom:1.5rem"
         data-track-order="<?= e($order['order_number']) ?>"
         data-track-status="<?= e($order['status']) ?>">
      <h2 class="card__title">Progress</h2>
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
            ? 'Collect from ' . e($restaurant['name'] ?? '') . ', ' . e($restaurant['address'] ?? '')
            : 'Delivering to ' . e($order['delivery_address']) ?>
      </p>
    </div>

    <div class="card" style="margin-bottom:1.5rem">
      <h2 class="card__title">Your restaurant</h2>
      <table class="data" style="box-shadow:none">
        <tbody>
          <tr>
            <th>Restaurant</th>
            <td>
              <a href="<?= e(restaurant_url($restaurant)) ?>"><?= e($restaurant['name']) ?></a>
              <?php if ($restaurant['cuisine']): ?>
                <span class="text-muted">&middot; <?= e($restaurant['cuisine']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php if ($restaurant['address']): ?>
            <tr><th>Address</th><td><?= e($restaurant['address']) ?></td></tr>
          <?php endif; ?>
          <?php if ($restaurant['phone']): ?>
            <tr>
              <th>Phone</th>
              <td><a href="tel:<?= e(preg_replace('/\s+/', '', $restaurant['phone'])) ?>"><?= e($restaurant['phone']) ?></a></td>
            </tr>
          <?php endif; ?>
          <?php if ($restaurant['opening_hours']): ?>
            <tr><th>Hours</th><td><?= e($restaurant['opening_hours']) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h2 class="card__title">Order summary</h2>

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
        <div class="totals__row"><dt>Subtotal</dt><dd><?= e(money($order['subtotal'])) ?></dd></div>
        <?php if ((float) $order['delivery_fee'] > 0): ?>
          <div class="totals__row"><dt>Delivery</dt><dd><?= e(money($order['delivery_fee'])) ?></dd></div>
        <?php endif; ?>
        <?php if ((float) $order['tax'] > 0): ?>
          <div class="totals__row"><dt>VAT</dt><dd><?= e(money($order['tax'])) ?></dd></div>
        <?php endif; ?>
        <div class="totals__row totals__row--grand">
          <dt>Total</dt><dd><?= e(money($order['total'])) ?></dd>
        </div>
      </dl>

      <p style="margin-bottom:.4rem">
        <span class="badge badge--<?= e($order['payment_status']) ?>">
          <?= $isCash && !$paid
              ? ($order['order_type'] === 'pickup' ? 'Pay on collection' : 'Pay on delivery')
              : e(ucfirst($order['payment_status'])) ?>
        </span>
      </p>

      <?php if ($order['notes']): ?>
        <p class="text-muted" style="font-size:.9rem">
          Note for the kitchen: <?= e($order['notes']) ?>
        </p>
      <?php endif; ?>
    </div>

    <p style="margin-top:1.75rem">
      <a class="btn btn--ghost" href="<?= url('track.php?order=' . urlencode($order['order_number'])) ?>">
        Track this order
      </a>
      <a class="btn btn--ghost" href="<?= e(restaurant_url($restaurant)) ?>">
        Order again from <?= e($restaurant['name']) ?>
      </a>
      <a class="btn btn--ghost" href="<?= url('restaurants.php') ?>">Other restaurants</a>
    </p>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
