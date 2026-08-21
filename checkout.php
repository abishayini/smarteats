<?php
/**
 * Smart Eats - checkout
 *
 * FR-03 submit order and confirmation.
 *
 * Collects contact and delivery details, revalidates the basket, then
 * writes the order. Card orders continue to the payment page; cash
 * orders are confirmed immediately.
 *
 * The restaurant is taken from the basket rather than from the form, so
 * the order cannot be redirected to a different kitchen by editing a
 * hidden field. Its name, address and collection details are shown
 * throughout, because this is the last screen before money moves.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/restaurants.php';
require_once __DIR__ . '/includes/stripe.php';
require_once __DIR__ . '/includes/hours.php';

$page_title = 'Checkout';

$restaurant = cart_restaurant();
$items      = cart_items();

if (!$restaurant || !$items) {
    flash('Your basket is empty.', 'info');
    redirect('cart.php');
}

if (!restaurant_is_open($restaurant)) {
    flash(restaurant_closed_reason($restaurant) . ' Your basket has been kept.', 'warning');
    redirect('cart.php');
}

// The same validation the basket page runs, so a customer cannot skip
// past a sold-out dish or the minimum order by jumping straight here.
$problems = cart_problems();
if ($problems) {
    foreach ($problems as $problem) {
        flash($problem, 'warning');
    }
    redirect('cart.php');
}

$orderType = current_order_type();
$totals    = cart_totals($orderType);
$user      = current_user();

$values = [
    'name'           => $user['full_name'] ?? '',
    'email'          => $user['email'] ?? '',
    'phone'          => $user['phone'] ?? '',
    'address'        => $user['address'] ?? '',
    'notes'          => '',
    'payment_method' => stripe_configured() ? 'card' : 'cash',
];

$errors = [];

if (is_post()) {
    verify_csrf();

    foreach (array_keys($values) as $key) {
        $values[$key] = input($key, $values[$key]);
    }

    if ($values['name'] === '') {
        $errors['name'] = 'Enter the name for the order.';
    }

    if ($values['phone'] === '') {
        $errors['phone'] = 'Enter a phone number so the kitchen can reach you.';
    } elseif (!is_valid_phone($values['phone'])) {
        $errors['phone'] = 'Enter a valid phone number.';
    }

    if ($values['email'] !== '' && !is_valid_email($values['email'])) {
        $errors['email'] = 'That does not look like a valid email address.';
    }

    if ($orderType === 'delivery' && $values['address'] === '') {
        $errors['address'] = 'Enter the delivery address.';
    } elseif ($orderType === 'delivery') {
        // A restaurant that has listed the postcodes it covers should not
        // be handed an order it cannot deliver. An address with no
        // recognisable postcode is allowed through rather than blocked,
        // because refusing a real customer is the worse mistake.
        [$canDeliver, $zoneMessage] = delivers_to($restaurant, $values['address']);
        if (!$canDeliver) {
            $errors['address'] = $zoneMessage;
        }
    }

    if (!in_array($values['payment_method'], ['card', 'cash'], true)) {
        $errors['payment_method'] = 'Choose how you would like to pay.';
    } elseif ($values['payment_method'] === 'card' && !stripe_configured()) {
        $errors['payment_method'] = 'Card payment is not available right now. Choose cash instead.';
    }

    if (!$errors) {
        try {
            $order = create_order_from_cart([
                'name'           => $values['name'],
                'email'          => $values['email'],
                'phone'          => $values['phone'],
                'address'        => $values['address'],
                'notes'          => $values['notes'],
                'order_type'     => $orderType,
                'payment_method' => $values['payment_method'],
            ]);

            if ($values['payment_method'] === 'cash') {
                // Nothing to collect online, so the kitchen can start.
                set_order_status((int) $order['id'], 'confirmed', null, 'Cash order confirmed');
                flash(
                    'Order ' . $order['order_number'] . ' has been sent to '
                    . $restaurant['name'] . '.',
                    'success'
                );
                redirect('order_success.php?order=' . urlencode($order['order_number']));
            }

            redirect('payment.php?order=' . urlencode($order['order_number']));

        } catch (Throwable $e) {
            error_log('Order creation failed: ' . $e->getMessage());
            $errors['form'] = 'The order could not be placed. Nothing has been charged. Please try again.';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap">

    <h1>Checkout</h1>
    <p class="text-muted">
      Ordering from
      <a href="<?= e(restaurant_url($restaurant)) ?>"><strong><?= e($restaurant['name']) ?></strong></a>
      &middot;
      <?= $orderType === 'pickup'
          ? 'collection from ' . e($restaurant['address'])
          : 'delivery' ?>
      &middot; <a href="<?= url('cart.php') ?>">change</a>
    </p>

    <?php if (isset($errors['form'])): ?>
      <div class="alert alert--error" role="alert"><?= e($errors['form']) ?></div>
    <?php endif; ?>

    <div class="checkout">

      <form class="checkout__form" method="post" action="<?= url('checkout.php') ?>"
            data-once novalidate>
        <?= csrf_field() ?>

        <div class="card" style="margin-bottom:1.5rem">
          <h2 class="card__title">Your details</h2>

          <div class="<?= field_class($errors, 'name') ?>">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" value="<?= e($values['name']) ?>"
                   autocomplete="name" required>
            <?= field_error($errors, 'name') ?>
          </div>

          <div class="<?= field_class($errors, 'phone') ?>">
            <label for="phone">Phone number</label>
            <input type="tel" id="phone" name="phone" value="<?= e($values['phone']) ?>"
                   autocomplete="tel" required>
            <span class="field__hint">
              <?= e($restaurant['name']) ?> uses this if there is a question about your order.
            </span>
            <?= field_error($errors, 'phone') ?>
          </div>

          <div class="<?= field_class($errors, 'email') ?>">
            <label for="email">Email <span class="text-muted">(optional)</span></label>
            <input type="email" id="email" name="email" value="<?= e($values['email']) ?>"
                   autocomplete="email">
            <span class="field__hint">Used only for your order confirmation.</span>
            <?= field_error($errors, 'email') ?>
          </div>

          <?php if ($orderType === 'delivery'): ?>
            <?php $zones = delivery_zones($restaurant); ?>
            <div class="<?= field_class($errors, 'address') ?>">
              <label for="address">Delivery address</label>
              <textarea id="address" name="address" rows="3"
                        autocomplete="street-address" required><?= e($values['address']) ?></textarea>
              <?php if ($zones): ?>
                <span class="field__hint">
                  <?= e($restaurant['name']) ?> delivers to
                  <?= e(implode(', ', $zones)) ?>. Choose collection for anywhere else.
                </span>
              <?php endif; ?>
              <?= field_error($errors, 'address') ?>
            </div>
          <?php endif; ?>

          <div class="field">
            <label for="notes">Notes for the kitchen <span class="text-muted">(optional)</span></label>
            <textarea id="notes" name="notes" rows="2"
                      placeholder="Allergies, doorbell not working, no cutlery needed"><?= e($values['notes']) ?></textarea>
          </div>
        </div>

        <div class="card">
          <h2 class="card__title">Payment</h2>

          <?php if (!stripe_configured()): ?>
            <div class="alert alert--info" role="status">
              Card payment is not configured on this installation. Add your
              Stripe test keys to <code>config/config.php</code> to enable it.
            </div>
          <?php endif; ?>

          <div class="<?= field_class($errors, 'payment_method') ?>">
            <label class="pay-option<?= $values['payment_method'] === 'card' ? ' is-active' : '' ?>
                        <?= stripe_configured() ? '' : ' is-disabled' ?>">
              <input type="radio" name="payment_method" value="card"
                     <?= $values['payment_method'] === 'card' ? 'checked' : '' ?>
                     <?= stripe_configured() ? '' : 'disabled' ?>>
              <span>
                <strong>Pay by card now</strong>
                <small>Secure payment handled by Stripe. Test mode.</small>
              </span>
            </label>

            <label class="pay-option<?= $values['payment_method'] === 'cash' ? ' is-active' : '' ?>">
              <input type="radio" name="payment_method" value="cash"
                     <?= $values['payment_method'] === 'cash' ? 'checked' : '' ?>>
              <span>
                <strong>Pay <?= $orderType === 'pickup' ? 'on collection' : 'on delivery' ?></strong>
                <small>Cash or card in person when you receive your order.</small>
              </span>
            </label>

            <?= field_error($errors, 'payment_method') ?>
          </div>

          <button class="btn btn--accent btn--full btn--lg" type="submit" style="margin-top:1.25rem">
            Place order &middot; <?= e(money($totals['total'])) ?>
          </button>

          <p class="text-muted" style="font-size:.82rem;margin:.85rem 0 0">
            By placing this order you confirm the details above are correct.
            This order goes to <?= e($restaurant['name']) ?>.
          </p>
        </div>
      </form>

      <aside class="checkout__summary">
        <div class="card">
          <h2 class="card__title">Your order</h2>

          <p class="text-muted" style="margin-top:-.5rem;font-size:.9rem">
            <?= e($restaurant['name']) ?>
            <?php if ($restaurant['phone']): ?>
              &middot; <?= e($restaurant['phone']) ?>
            <?php endif; ?>
          </p>

          <ul class="summary-list">
            <?php foreach ($items as $item): ?>
              <li>
                <span class="summary-list__qty"><?= (int) $item['quantity'] ?>&times;</span>
                <span class="summary-list__name"><?= e($item['name']) ?></span>
                <span class="summary-list__price"><?= e(money($item['line_total'])) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>

          <dl class="totals">
            <div class="totals__row">
              <dt>Subtotal</dt><dd><?= e(money($totals['subtotal'])) ?></dd>
            </div>
            <?php if ($orderType === 'delivery'): ?>
              <div class="totals__row">
                <dt>Delivery</dt>
                <dd><?= $totals['delivery_fee'] > 0 ? e(money($totals['delivery_fee'])) : 'Free' ?></dd>
              </div>
            <?php endif; ?>
            <?php if ($totals['tax'] > 0): ?>
              <div class="totals__row"><dt>VAT</dt><dd><?= e(money($totals['tax'])) ?></dd></div>
            <?php endif; ?>
            <div class="totals__row totals__row--grand">
              <dt>Total</dt><dd><?= e(money($totals['total'])) ?></dd>
            </div>
          </dl>

          <p style="margin:0"><a href="<?= url('cart.php') ?>">Edit basket</a></p>
        </div>
      </aside>

    </div>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
