<?php
/**
 * Smart Eats - card payment
 *
 * FR-04 online payment.
 *
 * Creates a PaymentIntent for an existing order and mounts Stripe's
 * Payment Element. Card details are entered inside an iframe served by
 * Stripe and are never submitted to this application, so no card data
 * passes through the platform's server or database.
 *
 * The intent metadata carries the restaurant id alongside the order, so
 * a payment can be traced to the right business in the Stripe dashboard
 * without joining back to this database.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/restaurants.php';
require_once __DIR__ . '/includes/stripe.php';

$order = order_by_number(input('order'));

if (!$order || !can_view_order($order)) {
    http_response_code(404);
    flash('That order could not be found.', 'error');
    redirect('index.php');
}

$restaurant = restaurant_by_id((int) $order['restaurant_id']);

if ($order['payment_status'] === 'paid') {
    redirect('order_success.php?order=' . urlencode($order['order_number']));
}

if ($order['status'] === 'cancelled') {
    flash('That order was cancelled and cannot be paid for.', 'error');
    redirect('index.php');
}

if (!stripe_configured()) {
    flash('Card payment is not configured on this installation.', 'error');
    redirect('index.php');
}

$page_title = 'Payment';
$currency   = setting('currency_code', 'GBP');
$intentError = '';
$clientSecret = '';

// Reuse the existing intent if the customer reloads the page, rather
// than creating a new one on every visit.
$intent = null;
if ($order['payment_intent_id']) {
    $existing = stripe_retrieve_payment_intent($order['payment_intent_id']);
    if ($existing['ok'] && in_array($existing['data']['status'] ?? '', ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
        $intent = $existing['data'];
    }
}

if (!$intent) {
    $created = stripe_create_payment_intent(
        (float) $order['total'],
        $currency,
        [
            'order_id'        => $order['id'],
            'order_number'    => $order['order_number'],
            'restaurant_id'   => $order['restaurant_id'],
            'restaurant_name' => $restaurant['name'] ?? '',
        ]
    );

    if ($created['ok']) {
        $intent = $created['data'];
        db_run(
            'UPDATE orders SET payment_intent_id = ? WHERE id = ?',
            [$intent['id'], $order['id']]
        );
    } else {
        $intentError = $created['error'];
    }
}

if ($intent) {
    $clientSecret = $intent['client_secret'] ?? '';
}

$lines = order_lines((int) $order['id']);

include __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap" style="max-width:900px">

    <h1>Payment</h1>
    <p class="text-muted">
      Order <strong><?= e($order['order_number']) ?></strong> with
      <strong><?= e($restaurant['name'] ?? 'the restaurant') ?></strong> is reserved.
      It is sent to their kitchen once payment succeeds.
    </p>

    <?php if ($intentError): ?>
      <div class="alert alert--error" role="alert">
        <?= e($intentError) ?>
      </div>
      <p><a class="btn btn--ghost" href="<?= url('cart.php') ?>">Back to basket</a></p>
    <?php else: ?>

      <div class="checkout">

        <div class="checkout__form">
          <div class="card">
            <h2 class="card__title">Card details</h2>

            <div class="alert alert--info" role="status" style="margin-top:0">
              Test mode. Use card <code>4242 4242 4242 4242</code>, any future
              expiry date, any three digit code and any postcode.
            </div>

            <div id="payment-element" style="margin:1.25rem 0"></div>

            <div id="payment-message" class="alert alert--error" role="alert" hidden></div>

            <button class="btn btn--accent btn--full btn--lg" id="pay-button" type="button">
              Pay <?= e(money($order['total'])) ?>
            </button>

            <p class="text-muted" style="font-size:.82rem;margin:.85rem 0 0">
              Card details are handled by Stripe and never reach this server.
            </p>
          </div>
        </div>

        <aside class="checkout__summary">
          <div class="card">
            <h2 class="card__title">Your order</h2>

            <p class="text-muted" style="margin-top:-.5rem;font-size:.9rem">
              <?= e($restaurant['name'] ?? '') ?>
            </p>

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
          </div>
        </aside>

      </div>

      <script src="https://js.stripe.com/v3/"></script>
      <script>
        window.SMARTEATS_PAY = {
          publishableKey: <?= json_encode(STRIPE_PUBLISHABLE_KEY) ?>,
          clientSecret:   <?= json_encode($clientSecret) ?>,
          returnUrl:      <?= json_encode(url('order_success.php?order=' . urlencode($order['order_number']))) ?>
        };
      </script>
      <script src="<?= ASSETS_URL ?>/js/checkout.js"></script>

    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
