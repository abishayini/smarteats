<?php
/**
 * Smart Eats - card payment status (Phase 11B)
 *
 * Setting up Stripe fails quietly. A key pasted with a trailing space, a
 * live key used where a test key was meant, or a webhook secret that was
 * never filled in all produce the same symptom: checkout offers cash
 * only, or a payment appears to succeed and the order stays unpaid.
 *
 * This screen checks each part separately and says which one is wrong,
 * rather than leaving that to be worked out from a blank payment form.
 * It reads the keys from config/config.php and never writes them: keys
 * belong in a file outside the database, not in a settings table where a
 * database export would carry them.
 *
 * Only the last four characters of any key are ever shown.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restaurants.php';
require_once __DIR__ . '/../includes/stripe.php';

require_role('admin');
handle_restaurant_switch();

$page_title = 'Card payments';

$publishable = (string) STRIPE_PUBLISHABLE_KEY;
$secret      = (string) STRIPE_SECRET_KEY;
$webhook     = (string) STRIPE_WEBHOOK_SECRET;

/** Show only enough of a key to tell two of them apart. */
function key_hint(string $key): string
{
    if ($key === '' || str_contains($key, 'replace_me')) {
        return 'not set';
    }
    return substr($key, 0, 8) . '...' . substr($key, -4);
}

/**
 * Check one key's shape without contacting Stripe.
 * @return array{state:string, note:string}
 */
function key_check(string $key, string $expectedPrefix, string $name): array
{
    if ($key === '' || str_contains($key, 'replace_me')) {
        return ['state' => 'missing', 'note' => $name . ' has not been filled in yet.'];
    }

    if ($key !== trim($key)) {
        return ['state' => 'bad', 'note' => 'There is a space at the start or end of the key. Paste it again.'];
    }

    if (str_starts_with($key, str_replace('test', 'live', $expectedPrefix))) {
        return ['state' => 'bad', 'note' => 'This is a live key. Use a test key for the project.'];
    }

    if (!str_starts_with($key, $expectedPrefix)) {
        return ['state' => 'bad', 'note' => 'Expected a key beginning ' . $expectedPrefix];
    }

    return ['state' => 'ok', 'note' => 'Looks right.'];
}

$checks = [
    'Publishable key' => key_check($publishable, 'pk_test_', 'The publishable key'),
    'Secret key'      => key_check($secret,      'sk_test_', 'The secret key'),
];

// The webhook secret is optional for a demonstration, so it is reported
// separately rather than as a failure.
$webhookSet = $webhook !== '' && !str_contains($webhook, 'replace_me');

// A live call, only when both keys look plausible and the administrator
// asks for it. Creating and then reading back a tiny PaymentIntent is
// the only way to prove the secret key is accepted by Stripe.
$liveResult = null;

if (is_post() && input('action') === 'test_connection') {
    verify_csrf();

    if (!stripe_configured()) {
        $liveResult = ['ok' => false, 'message' => 'Fill in both keys first.'];
    } else {
        $created = stripe_create_payment_intent(
            1.00,
            setting('currency_code', 'GBP'),
            ['purpose' => 'smart_eats_connection_test']
        );

        if ($created['ok']) {
            $liveResult = [
                'ok'      => true,
                'message' => 'Stripe accepted the secret key and created a test PaymentIntent ('
                           . ($created['data']['id'] ?? 'unknown') . '). '
                           . 'Nothing has been charged; a PaymentIntent that is never '
                           . 'confirmed simply expires.',
            ];
        } else {
            $liveResult = ['ok' => false, 'message' => $created['error']];
        }
    }
}

$cardOrders = db_one(
    "SELECT COUNT(*) AS attempted,
            SUM(payment_status = 'paid')   AS paid,
            SUM(payment_status = 'failed') AS failed
     FROM orders WHERE payment_method = 'card'"
);

$recentPayments = db_all(
    'SELECT p.stripe_intent_id, p.amount, p.status, p.created_at,
            o.order_number, r.name AS restaurant_name
     FROM payments p
     JOIN orders o      ON o.id = p.order_id
     JOIN restaurants r ON r.id = o.restaurant_id
     ORDER BY p.created_at DESC
     LIMIT 10'
);

include __DIR__ . '/../includes/panel_header.php';
?>

<div class="card" style="margin-bottom:1.5rem;
     border-left:4px solid var(--<?= stripe_configured() ? 'ok' : 'err' ?>)">
  <h2 class="card__title" style="margin-bottom:.25rem">
    <?= stripe_configured()
        ? 'Card payment is switched on'
        : 'Card payment is switched off' ?>
  </h2>
  <p class="text-muted" style="margin:0;font-size:.9rem">
    <?= stripe_configured()
        ? 'Checkout offers paying by card as well as paying in person.'
        : 'Checkout offers cash on delivery or collection only, and says so on the page. '
          . 'Nothing is broken; the keys have not been added yet.' ?>
  </p>
</div>

<div class="grid grid--2" style="align-items:start">

  <div class="card">
    <h2 class="card__title">Keys</h2>

    <table class="data" style="box-shadow:none">
      <tbody>
        <?php foreach ($checks as $label => $check): ?>
          <tr>
            <th style="white-space:nowrap"><?= e($label) ?></th>
            <td>
              <span class="badge badge--<?= $check['state'] === 'ok' ? 'completed' : ($check['state'] === 'missing' ? 'pending' : 'cancelled') ?>">
                <?= $check['state'] === 'ok' ? 'OK' : ($check['state'] === 'missing' ? 'Not set' : 'Problem') ?>
              </span>
              <br><span class="text-muted" style="font-size:.82rem"><?= e($check['note']) ?></span>
            </td>
            <td class="text-right text-muted" style="font-size:.8rem">
              <?= e(key_hint($label === 'Publishable key' ? $publishable : $secret)) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <tr>
          <th style="white-space:nowrap">Webhook secret</th>
          <td>
            <span class="badge badge--<?= $webhookSet ? 'completed' : 'pending' ?>">
              <?= $webhookSet ? 'OK' : 'Optional' ?>
            </span>
            <br><span class="text-muted" style="font-size:.82rem">
              <?= $webhookSet
                  ? 'Payments are confirmed even if the customer closes the browser.'
                  : 'Not needed for a demonstration. Without it, an order is only marked paid '
                    . 'when the customer returns to the confirmation page.' ?>
            </span>
          </td>
          <td class="text-right text-muted" style="font-size:.8rem">
            <?= e(key_hint($webhook)) ?>
          </td>
        </tr>
      </tbody>
    </table>

    <p class="text-muted" style="font-size:.86rem;margin-top:1rem">
      Keys live in <code>config/config.php</code>. They are deliberately not
      editable here: a key stored in the database would end up inside every
      database export.
    </p>

    <form method="post" style="margin-top:1rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="test_connection">
      <button class="btn btn--accent" type="submit" <?= stripe_configured() ? '' : 'disabled' ?>>
        Test the connection to Stripe
      </button>
    </form>

    <?php if ($liveResult): ?>
      <div class="alert alert--<?= $liveResult['ok'] ? 'success' : 'error' ?>"
           role="<?= $liveResult['ok'] ? 'status' : 'alert' ?>" style="margin-top:1rem">
        <?= e($liveResult['message']) ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 class="card__title">Setting it up</h2>
    <ol class="stack" style="padding-left:1.15rem;font-size:.92rem">
      <li>
        Create a free account at <strong>stripe.com</strong>. No business details
        or bank account are needed to use test mode.
      </li>
      <li>
        In the Stripe dashboard, switch <strong>Test mode</strong> on using the
        toggle at the top right. Every key you copy after that starts with
        <code>pk_test_</code> or <code>sk_test_</code>.
      </li>
      <li>
        Open <strong>Developers &rarr; API keys</strong> and copy both the
        publishable key and the secret key.
      </li>
      <li>
        Paste them into <code>config/config.php</code>, replacing
        <code>pk_test_replace_me</code> and <code>sk_test_replace_me</code>.
      </li>
      <li>Reload this page. Both rows should read OK.</li>
      <li>
        Place an order and pay with card number
        <code>4242&nbsp;4242&nbsp;4242&nbsp;4242</code>, any future expiry date,
        any three digit code and any postcode.
      </li>
    </ol>

    <p class="text-muted" style="font-size:.86rem">
      Test mode moves no real money and needs no real card. Cards used in test
      mode are rejected in live mode and the reverse, so the two cannot be
      confused by accident.
    </p>
  </div>

</div>

<div class="card" style="margin-top:1.5rem">
  <h2 class="card__title">Card payments so far</h2>

  <div class="stats" style="margin-bottom:1rem">
    <div class="stat stat--accent">
      <p class="stat__label">Card orders</p>
      <p class="stat__value"><?= (int) $cardOrders['attempted'] ?></p>
    </div>
    <div class="stat stat--ok">
      <p class="stat__label">Paid</p>
      <p class="stat__value"><?= (int) $cardOrders['paid'] ?></p>
    </div>
    <div class="stat<?= (int) $cardOrders['failed'] ? ' stat--warn' : '' ?>">
      <p class="stat__label">Failed</p>
      <p class="stat__value"><?= (int) $cardOrders['failed'] ?></p>
    </div>
  </div>

  <?php if (!$recentPayments): ?>
    <p class="text-muted">No payments recorded yet.</p>
  <?php else: ?>
    <div class="table-scroll">
      <table class="data">
        <thead>
          <tr>
            <th>When</th><th>Order</th><th>Restaurant</th>
            <th>Stripe reference</th><th>Status</th><th class="text-right">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentPayments as $payment): ?>
            <tr>
              <td><?= e(pretty_date($payment['created_at'])) ?></td>
              <td><?= e($payment['order_number']) ?></td>
              <td><?= e($payment['restaurant_name']) ?></td>
              <td class="text-muted" style="font-size:.8rem">
                <?= e($payment['stripe_intent_id'] ?? '') ?>
              </td>
              <td>
                <span class="badge badge--<?= $payment['status'] === 'succeeded' ? 'completed' : 'cancelled' ?>">
                  <?= e($payment['status']) ?>
                </span>
              </td>
              <td class="text-right"><?= e(money($payment['amount'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-muted" style="font-size:.86rem;margin-top:.85rem">
      The Stripe reference is searchable in the Stripe dashboard, where each
      payment also carries the order number and the restaurant it belongs to.
    </p>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/panel_footer.php'; ?>
