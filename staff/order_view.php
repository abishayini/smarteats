<?php
/**
 * Smart Eats - order detail for staff
 *
 * The full record behind a card on the board: contact details, every
 * line item, the payment position and the complete status history with
 * timestamps.
 *
 * The access check is the important line in this file. An order id in
 * the address bar is trivially editable, so the order is loaded first
 * and then checked against the restaurant on the signed-in account.
 * Without that, any kitchen on the platform could read any other
 * kitchen's orders, including customer names, addresses and phone
 * numbers, by counting upwards.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_once __DIR__ . '/../includes/restaurants.php';

require_role('staff', 'vendor', 'admin');
require_active_restaurant();

// The sidebar switcher posts back to this page, so it is dealt with
// before this screen's own POST handling sees an action it cannot place.
handle_restaurant_switch();

$user  = current_user();
$order = order_by_id((int) input('id'));

if (!$order) {
    flash('That order could not be found.', 'error');
    redirect('staff/dashboard.php');
}

// The order must belong to a restaurant this account may act for.
require_restaurant_access((int) $order['restaurant_id']);

$restaurant = restaurant_by_id((int) $order['restaurant_id']);

if (is_post()) {
    verify_csrf();
    $status = input('status');

    if (!in_array($status, next_statuses($order), true)) {
        flash('That change is no longer possible for this order.', 'warning');
    } else {
        set_order_status(
            (int) $order['id'],
            $status,
            (int) $user['id'],
            'Updated by ' . $user['full_name']
        );
        flash('Order updated to ' . strtolower(status_label($status)) . '.', 'success');
    }

    redirect('staff/order_view.php?id=' . (int) $order['id']);
}

$page_title = 'Order ' . $order['order_number'];
$lines      = order_lines((int) $order['id']);
$history    = order_history((int) $order['id']);

// How long the order has been open, or how long it took to complete.
$start = strtotime($order['created_at']);
$end   = $order['status'] === 'completed' && $history
    ? strtotime(end($history)['changed_at'])
    : time();
$elapsed = max(0, (int) floor(($end - $start) / 60));

include __DIR__ . '/../includes/panel_header.php';
?>

<div class="row row--between no-print" style="margin-bottom:1.25rem">
  <a href="<?= url('staff/dashboard.php') ?>">&larr; Back to the board</a>
  <button class="btn btn--sm btn--ghost" type="button" onclick="window.print()">
    Print kitchen ticket
  </button>
</div>

<?php // A compact ticket for the pass, shown only when printing. ?>
<div class="ticket print-only">
  <p class="ticket__meta"><strong><?= e($restaurant['name']) ?></strong></p>
  <h2 class="ticket__ref"><?= e($order['order_number']) ?></h2>
  <p class="ticket__meta">
    <?= $order['order_type'] === 'pickup' ? 'COLLECTION' : 'DELIVERY' ?>
    &middot; <?= e(pretty_date($order['created_at'])) ?>
  </p>
  <p class="ticket__meta">
    <?= e($order['customer_name']) ?> &middot; <?= e($order['customer_phone']) ?>
  </p>
  <?php if ($order['order_type'] === 'delivery'): ?>
    <p class="ticket__meta"><?= e($order['delivery_address']) ?></p>
  <?php endif; ?>

  <ul class="ticket__items">
    <?php foreach ($lines as $line): ?>
      <li><strong><?= (int) $line['quantity'] ?> x</strong> <?= e($line['item_name']) ?></li>
    <?php endforeach; ?>
  </ul>

  <?php if ($order['notes']): ?>
    <p class="ticket__note">NOTE: <?= e($order['notes']) ?></p>
  <?php endif; ?>

  <p class="ticket__total">
    Total <?= e(money($order['total'])) ?> &middot;
    <?= $order['payment_status'] === 'paid'
        ? 'PAID'
        : ($order['payment_method'] === 'cash' ? 'COLLECT PAYMENT' : 'UNPAID') ?>
  </p>
</div>

<div class="stats no-print">
  <div class="stat">
    <p class="stat__label">Status</p>
    <p class="stat__value" style="font-size:1.2rem"><?= e(status_label($order['status'])) ?></p>
  </div>
  <div class="stat <?= $order['payment_status'] === 'paid' ? 'stat--ok' : 'stat--warn' ?>">
    <p class="stat__label">Payment</p>
    <p class="stat__value" style="font-size:1.2rem">
      <?= $order['payment_method'] === 'cash' && $order['payment_status'] !== 'paid'
          ? 'Cash on ' . ($order['order_type'] === 'pickup' ? 'collection' : 'delivery')
          : e(ucfirst($order['payment_status'])) ?>
    </p>
  </div>
  <div class="stat stat--accent">
    <p class="stat__label"><?= $order['status'] === 'completed' ? 'Completed in' : 'Open for' ?></p>
    <p class="stat__value" style="font-size:1.2rem"><?= $elapsed ?> min</p>
  </div>
  <div class="stat">
    <p class="stat__label">Total</p>
    <p class="stat__value" style="font-size:1.2rem"><?= e(money($order['total'])) ?></p>
  </div>
</div>

<?php if (has_role('admin')): ?>
  <p class="text-muted no-print" style="margin-bottom:1.25rem">
    Restaurant: <strong><?= e($restaurant['name']) ?></strong>
    &middot; <a href="<?= e(restaurant_url($restaurant)) ?>">view menu</a>
  </p>
<?php endif; ?>

<?php if (next_statuses($order)): ?>
  <div class="card no-print" style="margin-bottom:1.5rem">
    <h2 class="card__title">Move this order on</h2>
    <div class="row">
      <?php foreach (next_statuses($order) as $status): ?>
        <form method="post" action="<?= url('staff/order_view.php?id=' . (int) $order['id']) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="status" value="<?= e($status) ?>">
          <button class="btn <?= $status === 'cancelled' ? 'btn--ghost' : 'btn--accent' ?>"
                  type="submit"
                  <?= $status === 'cancelled'
                      ? 'data-confirm="Cancel order ' . e($order['order_number']) . '?"'
                      : '' ?>>
            <?= e(transition_label($status, $order)) ?>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="grid grid--2">

  <div class="card">
    <h2 class="card__title">Customer</h2>
    <table class="data" style="box-shadow:none">
      <tbody>
        <tr><th>Name</th><td><?= e($order['customer_name']) ?></td></tr>
        <tr><th>Phone</th><td><a href="tel:<?= e(preg_replace('/\s+/', '', $order['customer_phone'])) ?>"><?= e($order['customer_phone']) ?></a></td></tr>
        <?php if ($order['customer_email']): ?>
          <tr><th>Email</th><td><?= e($order['customer_email']) ?></td></tr>
        <?php endif; ?>
        <tr><th>Type</th><td><?= $order['order_type'] === 'pickup' ? 'Collection' : 'Delivery' ?></td></tr>
        <?php if ($order['order_type'] === 'delivery'): ?>
          <tr><th>Address</th><td><?= nl2br(e($order['delivery_address'])) ?></td></tr>
        <?php endif; ?>
        <tr><th>Placed</th><td><?= e(pretty_date($order['created_at'])) ?></td></tr>
      </tbody>
    </table>

    <?php if ($order['notes']): ?>
      <div class="alert alert--info" role="note" style="margin-bottom:0">
        <strong>Note from the customer:</strong> <?= e($order['notes']) ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 class="card__title">Items</h2>
    <table class="data" style="box-shadow:none">
      <thead>
        <tr><th>Item</th><th>Qty</th><th class="text-right">Total</th></tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $line): ?>
          <tr>
            <td><?= e($line['item_name']) ?></td>
            <td><?= (int) $line['quantity'] ?></td>
            <td class="text-right"><?= e(money($line['line_total'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr>
          <th>Subtotal</th><td></td>
          <td class="text-right"><?= e(money($order['subtotal'])) ?></td>
        </tr>
        <?php if ((float) $order['delivery_fee'] > 0): ?>
          <tr><th>Delivery</th><td></td><td class="text-right"><?= e(money($order['delivery_fee'])) ?></td></tr>
        <?php endif; ?>
        <?php if ((float) $order['tax'] > 0): ?>
          <tr><th>VAT</th><td></td><td class="text-right"><?= e(money($order['tax'])) ?></td></tr>
        <?php endif; ?>
        <tr>
          <th>Total</th><td></td>
          <td class="text-right"><strong><?= e(money($order['total'])) ?></strong></td>
        </tr>
      </tbody>
    </table>
  </div>

</div>

<div class="card" style="margin-top:1.5rem">
  <h2 class="card__title">History</h2>
  <p class="text-muted" style="font-size:.88rem">
    Every status change is recorded with a timestamp, which is what makes
    order processing time measurable.
  </p>
  <div class="table-scroll">
    <table class="data">
      <thead>
        <tr><th>Time</th><th>Status</th><th>Note</th></tr>
      </thead>
      <tbody>
        <?php foreach ($history as $entry): ?>
          <tr>
            <td><?= e(pretty_date($entry['changed_at'])) ?></td>
            <td><span class="badge badge--<?= e($entry['status']) ?>"><?= e(status_label($entry['status'])) ?></span></td>
            <td><?= e($entry['note'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/panel_footer.php'; ?>
