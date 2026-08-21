<?php
/**
 * Smart Eats - reports (Phase 11B)
 *
 * FR-27 sales reporting, FR-28 order processing time.
 *
 * The processing-time table is the part of this system that answers the
 * research question. Every status change has been written to
 * order_status_history with a timestamp since Phase 3, which means the
 * time an order spends waiting to be accepted, being cooked, and waiting
 * to go out can be measured rather than estimated. A restaurant using
 * paper tickets cannot produce these numbers at all.
 *
 * Scoping works as everywhere else in the panel: a vendor sees their own
 * restaurant, an administrator sees whichever restaurant is selected, or
 * the whole platform when none is.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/orders.php';
require_once __DIR__ . '/../includes/restaurants.php';

require_role('admin', 'vendor');
require_active_restaurant();
handle_restaurant_switch();

$page_title = 'Reports';

$scopeId    = panel_restaurant_id();
$isPlatform = has_role('admin') && !$scopeId;

// Default to the last 30 days, which is long enough to be meaningful
// and short enough to still be about current trading.
$from = input('from') !== '' ? input('from') : date('Y-m-d', strtotime('-29 days'));
$to   = input('to')   !== '' ? input('to')   : date('Y-m-d');

$where  = ['DATE(o.created_at) BETWEEN ? AND ?'];
$params = [$from, $to];

if ($scopeId) {
    $where[]  = 'o.restaurant_id = ?';
    $params[] = $scopeId;
}

$clause = 'WHERE ' . implode(' AND ', $where);

/* ------------------------------------------------------------------ */
/* Headline figures                                                    */
/* ------------------------------------------------------------------ */

$totals = db_one(
    "SELECT COUNT(*) AS orders,
            COALESCE(SUM(CASE WHEN o.status <> 'cancelled' THEN o.total END), 0) AS revenue,
            COALESCE(AVG(CASE WHEN o.status <> 'cancelled' THEN o.total END), 0) AS average,
            SUM(o.status = 'cancelled')            AS cancelled,
            SUM(o.order_type = 'delivery')         AS delivery,
            SUM(o.payment_method = 'card')         AS card
     FROM orders o $clause",
    $params
);

/* ------------------------------------------------------------------ */
/* Orders per day, for the bar chart                                   */
/* ------------------------------------------------------------------ */

$daily = db_all(
    "SELECT DATE(o.created_at) AS day, COUNT(*) AS orders,
            COALESCE(SUM(CASE WHEN o.status <> 'cancelled' THEN o.total END), 0) AS revenue
     FROM orders o $clause
     GROUP BY DATE(o.created_at)
     ORDER BY day",
    $params
);

$peakOrders = 0;
foreach ($daily as $row) {
    $peakOrders = max($peakOrders, (int) $row['orders']);
}

/* ------------------------------------------------------------------ */
/* Best selling dishes                                                 */
/* ------------------------------------------------------------------ */

$topDishes = db_all(
    "SELECT oi.item_name, r.name AS restaurant_name,
            SUM(oi.quantity) AS units,
            SUM(oi.line_total) AS revenue
     FROM order_items oi
     JOIN orders o      ON o.id = oi.order_id
     JOIN restaurants r ON r.id = o.restaurant_id
     $clause AND o.status <> 'cancelled'
     GROUP BY oi.item_name, r.name
     ORDER BY units DESC
     LIMIT 10",
    $params
);

/* ------------------------------------------------------------------ */
/* Processing time - the evidence for RQ1                              */
/*                                                                     */
/* Each stage is measured between the two history rows that bracket it. */
/* Only completed orders are counted, because a cancelled or still-open */
/* order has no end point and would drag an average towards nonsense.   */
/* ------------------------------------------------------------------ */

$stages = [
    'Placed to accepted'   => ['pending', 'confirmed'],
    'Accepted to cooking'  => ['confirmed', 'preparing'],
    'Cooking to ready'     => ['preparing', 'ready'],
    'Ready to completed'   => ['ready', 'completed'],
];

$stageStats = [];

foreach ($stages as $label => [$fromStatus, $toStatus]) {
    $sql = "SELECT COUNT(*) AS samples,
                   ROUND(AVG(gap), 1) AS average_minutes,
                   ROUND(MIN(gap), 1) AS fastest,
                   ROUND(MAX(gap), 1) AS slowest
            FROM (
              SELECT TIMESTAMPDIFF(SECOND, MIN(a.changed_at), MIN(b.changed_at)) / 60 AS gap
              FROM orders o
              JOIN order_status_history a
                ON a.order_id = o.id AND a.status = ?
              JOIN order_status_history b
                ON b.order_id = o.id AND b.status = ?
              $clause
                AND o.status = 'completed'
              GROUP BY o.id
              HAVING gap >= 0
            ) gaps";

    // The two status parameters come before the ones already in $params,
    // because they appear earlier in the statement.
    $stageStats[$label] = db_one($sql, array_merge([$fromStatus, $toStatus], $params));
}

$endToEnd = db_one(
    "SELECT COUNT(*) AS samples,
            ROUND(AVG(gap), 1) AS average_minutes,
            ROUND(MIN(gap), 1) AS fastest,
            ROUND(MAX(gap), 1) AS slowest
     FROM (
       SELECT TIMESTAMPDIFF(SECOND, MIN(h.changed_at), MAX(h.changed_at)) / 60 AS gap
       FROM orders o
       JOIN order_status_history h ON h.order_id = o.id
       $clause AND o.status = 'completed'
       GROUP BY o.id
     ) gaps",
    $params
);

/* ------------------------------------------------------------------ */
/* Settlement, for the platform administrator                          */
/* ------------------------------------------------------------------ */

$settlement = $isPlatform
    ? db_all(
        "SELECT r.name, r.commission_rate,
                COUNT(o.id) AS orders,
                COALESCE(SUM(o.total), 0) AS gross,
                COALESCE(SUM(o.total * r.commission_rate), 0) AS commission,
                COALESCE(SUM(o.total * (1 - r.commission_rate)), 0) AS net
         FROM restaurants r
         JOIN orders o ON o.restaurant_id = r.id
         WHERE DATE(o.created_at) BETWEEN ? AND ?
           AND o.status <> 'cancelled'
           AND o.payment_status = 'paid'
         GROUP BY r.id, r.name, r.commission_rate
         ORDER BY gross DESC",
        [$from, $to]
      )
    : [];

$myCommission = (!$isPlatform && $scopeId)
    ? db_one(
        "SELECT r.commission_rate,
                COALESCE(SUM(o.total), 0) AS gross,
                COALESCE(SUM(o.total * r.commission_rate), 0) AS commission,
                COALESCE(SUM(o.total * (1 - r.commission_rate)), 0) AS net
         FROM restaurants r
         LEFT JOIN orders o ON o.restaurant_id = r.id
              AND DATE(o.created_at) BETWEEN ? AND ?
              AND o.status <> 'cancelled' AND o.payment_status = 'paid'
         WHERE r.id = ?
         GROUP BY r.id, r.commission_rate",
        [$from, $to, $scopeId]
      )
    : null;

/** A minutes figure written the way a person would say it. */
function minutes_label($minutes): string
{
    if ($minutes === null) {
        return 'No data';
    }

    $minutes = (float) $minutes;

    if ($minutes < 1) {
        return round($minutes * 60) . ' sec';
    }
    if ($minutes < 90) {
        return round($minutes, 1) . ' min';
    }

    return round($minutes / 60, 1) . ' hr';
}

include __DIR__ . '/../includes/panel_header.php';
?>

<form class="card" method="get" style="margin-bottom:1.5rem">
  <div class="grid grid--3" style="gap:1rem">
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
      <a class="btn btn--ghost" href="<?= url('admin/reports.php') ?>">Last 30 days</a>
    </div>
  </div>
</form>

<div class="stats">
  <div class="stat stat--ok">
    <p class="stat__label">Revenue</p>
    <p class="stat__value" style="font-size:1.5rem"><?= e(money($totals['revenue'])) ?></p>
  </div>
  <div class="stat stat--accent">
    <p class="stat__label">Orders</p>
    <p class="stat__value"><?= (int) $totals['orders'] ?></p>
  </div>
  <div class="stat">
    <p class="stat__label">Average order</p>
    <p class="stat__value" style="font-size:1.5rem"><?= e(money($totals['average'])) ?></p>
  </div>
  <div class="stat<?= (int) $totals['cancelled'] ? ' stat--warn' : '' ?>">
    <p class="stat__label">Cancelled</p>
    <p class="stat__value"><?= (int) $totals['cancelled'] ?></p>
  </div>
</div>

<?php /* ---------------- Processing time ---------------- */ ?>
<div class="card" style="margin-bottom:1.5rem">
  <h2 class="card__title">How long orders take</h2>
  <p class="text-muted" style="margin-top:-.5rem;font-size:.9rem">
    Measured from the timestamps written every time an order changes status.
    Only completed orders are counted, because an order still in progress has
    no end point to measure to.
  </p>

  <?php if ((int) $endToEnd['samples'] === 0): ?>
    <div class="empty" style="padding:1.5rem">
      <p><strong>No completed orders in this period.</strong></p>
      <p>Place an order and take it through to completed on the live board, and
         the timings appear here.</p>
    </div>
  <?php else: ?>
    <div class="table-scroll">
      <table class="data">
        <thead>
          <tr>
            <th>Stage</th><th class="text-right">Average</th>
            <th class="text-right">Fastest</th><th class="text-right">Slowest</th>
            <th class="text-right">Orders measured</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($stageStats as $label => $row): ?>
            <tr>
              <td><?= e($label) ?></td>
              <td class="text-right"><strong><?= e(minutes_label($row['average_minutes'])) ?></strong></td>
              <td class="text-right text-muted"><?= e(minutes_label($row['fastest'])) ?></td>
              <td class="text-right text-muted"><?= e(minutes_label($row['slowest'])) ?></td>
              <td class="text-right"><?= (int) $row['samples'] ?></td>
            </tr>
          <?php endforeach; ?>
          <tr>
            <th>Placed to completed</th>
            <td class="text-right"><strong><?= e(minutes_label($endToEnd['average_minutes'])) ?></strong></td>
            <td class="text-right text-muted"><?= e(minutes_label($endToEnd['fastest'])) ?></td>
            <td class="text-right text-muted"><?= e(minutes_label($endToEnd['slowest'])) ?></td>
            <td class="text-right"><?= (int) $endToEnd['samples'] ?></td>
          </tr>
        </tbody>
      </table>
    </div>

    <p class="text-muted" style="font-size:.86rem;margin-top:1rem">
      A stage showing no data means no completed order in this period passed
      through it, which is normal for collection orders and the delivery stage.
    </p>
  <?php endif; ?>
</div>

<div class="grid grid--2" style="align-items:start">

  <?php /* ---------------- Orders per day ---------------- */ ?>
  <div class="card">
    <h2 class="card__title">Orders per day</h2>

    <?php if (!$daily): ?>
      <p class="text-muted">No orders in this period.</p>
    <?php else: ?>
      <table class="data" style="box-shadow:none">
        <tbody>
          <?php foreach (array_reverse($daily) as $row): ?>
            <?php $width = $peakOrders ? round(((int) $row['orders'] / $peakOrders) * 100) : 0; ?>
            <tr>
              <td style="white-space:nowrap;width:110px">
                <?= e(date('D j M', strtotime($row['day']))) ?>
              </td>
              <td>
                <?php // A plain div rather than a charting library: it needs
                      // no dependency and prints correctly. ?>
                <span style="display:block;height:10px;border-radius:5px;
                             background:var(--accent);width:<?= $width ?>%;min-width:2px"></span>
              </td>
              <td class="text-right" style="width:60px"><?= (int) $row['orders'] ?></td>
              <td class="text-right text-muted" style="width:90px"><?= e(money($row['revenue'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php /* ---------------- Best sellers ---------------- */ ?>
  <div class="card">
    <h2 class="card__title">Best selling dishes</h2>

    <?php if (!$topDishes): ?>
      <p class="text-muted">No dishes sold in this period.</p>
    <?php else: ?>
      <table class="data" style="box-shadow:none">
        <thead>
          <tr>
            <th>Dish</th>
            <?php if ($isPlatform): ?><th>Restaurant</th><?php endif; ?>
            <th class="text-right">Sold</th><th class="text-right">Revenue</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($topDishes as $dish): ?>
            <tr>
              <td><?= e($dish['item_name']) ?></td>
              <?php if ($isPlatform): ?>
                <td class="text-muted"><?= e($dish['restaurant_name']) ?></td>
              <?php endif; ?>
              <td class="text-right"><?= (int) $dish['units'] ?></td>
              <td class="text-right"><?= e(money($dish['revenue'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="text-muted" style="font-size:.86rem;margin-top:.75rem">
        Counted from the name stored on the order line, so a dish that has since
        been renamed or withdrawn still appears under the name it sold as.
      </p>
    <?php endif; ?>
  </div>

</div>

<?php /* ---------------- Order mix ---------------- */ ?>
<div class="card" style="margin-top:1.5rem">
  <h2 class="card__title">How customers ordered</h2>
  <?php
    $orderCount = max(1, (int) $totals['orders']);
    $mix = [
        'Delivery'          => (int) $totals['delivery'],
        'Collection'        => $orderCount - (int) $totals['delivery'],
        'Paid by card'      => (int) $totals['card'],
        'Paying in person'  => $orderCount - (int) $totals['card'],
    ];
  ?>
  <div class="grid grid--2" style="gap:1rem">
    <?php foreach ($mix as $label => $count): ?>
      <div>
        <p style="margin:0 0 .25rem;font-size:.9rem">
          <?= e($label) ?>
          <strong><?= $count ?></strong>
          <span class="text-muted">(<?= round(($count / $orderCount) * 100) ?>%)</span>
        </p>
        <span style="display:block;height:8px;border-radius:4px;background:var(--line)">
          <span style="display:block;height:8px;border-radius:4px;background:var(--accent);
                       width:<?= round(($count / $orderCount) * 100) ?>%"></span>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php /* ---------------- Settlement ---------------- */ ?>
<?php if ($isPlatform): ?>
  <div class="card" style="margin-top:1.5rem">
    <h2 class="card__title">Settlement</h2>
    <p class="text-muted" style="margin-top:-.5rem;font-size:.9rem">
      What each restaurant is owed for card payments already taken in this
      period. Commission is zero unless it has been set on that restaurant's
      settings, which is the platform's default position.
    </p>

    <?php if (!$settlement): ?>
      <p class="text-muted">No paid orders in this period.</p>
    <?php else: ?>
      <div class="table-scroll">
        <table class="data">
          <thead>
            <tr>
              <th>Restaurant</th><th class="text-right">Paid orders</th>
              <th class="text-right">Gross</th><th class="text-right">Commission</th>
              <th class="text-right">Payable</th>
            </tr>
          </thead>
          <tbody>
            <?php $gross = $commission = $net = 0; ?>
            <?php foreach ($settlement as $row): ?>
              <?php
                $gross      += (float) $row['gross'];
                $commission += (float) $row['commission'];
                $net        += (float) $row['net'];
              ?>
              <tr>
                <td>
                  <?= e($row['name']) ?>
                  <?php if ((float) $row['commission_rate'] > 0): ?>
                    <span class="text-muted" style="font-size:.8rem">
                      at <?= round((float) $row['commission_rate'] * 100, 2) ?>%
                    </span>
                  <?php endif; ?>
                </td>
                <td class="text-right"><?= (int) $row['orders'] ?></td>
                <td class="text-right"><?= e(money($row['gross'])) ?></td>
                <td class="text-right"><?= e(money($row['commission'])) ?></td>
                <td class="text-right"><strong><?= e(money($row['net'])) ?></strong></td>
              </tr>
            <?php endforeach; ?>
            <tr>
              <th>Total</th><td></td>
              <td class="text-right"><?= e(money($gross)) ?></td>
              <td class="text-right"><?= e(money($commission)) ?></td>
              <td class="text-right"><strong><?= e(money($net)) ?></strong></td>
            </tr>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php elseif ($myCommission): ?>
  <div class="card" style="margin-top:1.5rem">
    <h2 class="card__title">Your settlement</h2>
    <table class="data" style="box-shadow:none">
      <tbody>
        <tr><th>Card payments taken</th><td class="text-right"><?= e(money($myCommission['gross'])) ?></td></tr>
        <?php if ((float) $myCommission['commission_rate'] > 0): ?>
          <tr>
            <th>Platform commission at <?= round((float) $myCommission['commission_rate'] * 100, 2) ?>%</th>
            <td class="text-right"><?= e(money($myCommission['commission'])) ?></td>
          </tr>
        <?php endif; ?>
        <tr>
          <th>Payable to you</th>
          <td class="text-right"><strong><?= e(money($myCommission['net'])) ?></strong></td>
        </tr>
      </tbody>
    </table>
    <p class="text-muted" style="font-size:.86rem;margin-top:.85rem">
      Cash orders are not included, because that money never passed through the
      platform.
    </p>
  </div>
<?php endif; ?>

<p class="text-muted" style="font-size:.86rem;margin-top:1.5rem">
  <button class="link-button" type="button" onclick="window.print()">Print this report</button>
</p>

<?php include __DIR__ . '/../includes/panel_footer.php'; ?>
