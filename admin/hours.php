<?php
/**
 * Smart Eats - opening hours (Phase 11B)
 *
 * FR-25 scheduled trading hours.
 *
 * The switch at the top is the important part of this screen. Following
 * a schedule is opt-in, and stays opt-in, because a restaurant that has
 * been trading happily on the manual control should not have its
 * behaviour changed by an upgrade. Turning it on tells the customer
 * facing pages to close ordering outside these hours without anybody
 * having to press anything at eleven at night.
 *
 * The manual pause on the Settings screen still overrides everything. A
 * schedule handles the routine week; the switch handles the fryer
 * breaking.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restaurants.php';
require_once __DIR__ . '/../includes/hours.php';

require_role('admin', 'vendor');
require_active_restaurant();
handle_restaurant_switch();

$restaurant   = require_panel_restaurant();
$restaurantId = (int) $restaurant['id'];

$page_title = 'Opening hours';
$errors     = [];
$names      = weekday_names();

if (is_post()) {
    verify_csrf();

    if (input('action') === 'toggle_schedule') {
        $now = (int) $restaurant['uses_schedule'] === 1 ? 0 : 1;
        db_run('UPDATE restaurants SET uses_schedule = ? WHERE id = ?', [$now, $restaurantId]);
        flash(
            $now
                ? 'Ordering now follows your opening hours.'
                : 'Ordering no longer follows a schedule. The accepting-orders switch is back in sole control.',
            'success'
        );
        redirect('admin/hours.php');
    }

    if (input('action') === 'save_hours') {
        $opens   = $_POST['opens_at']  ?? [];
        $closes  = $_POST['closes_at'] ?? [];
        $closed  = $_POST['is_closed'] ?? [];

        for ($day = 0; $day <= 6; $day++) {
            $isClosed = isset($closed[$day]) ? 1 : 0;
            $open     = trim((string) ($opens[$day]  ?? '11:00'));
            $close    = trim((string) ($closes[$day] ?? '22:30'));

            if (!$isClosed) {
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $open)) {
                    $errors[$day] = 'Enter an opening time as HH:MM.';
                    continue;
                }
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $close)) {
                    $errors[$day] = 'Enter a closing time as HH:MM.';
                    continue;
                }
                // Equal times would mean a zero length day, which is
                // almost certainly a mistake rather than an overnight
                // shift, so it is rejected rather than guessed at.
                if ($open === $close) {
                    $errors[$day] = 'Opening and closing cannot be the same time.';
                    continue;
                }
            }

            db_run(
                'INSERT INTO restaurant_hours
                   (restaurant_id, day_of_week, opens_at, closes_at, is_closed)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   opens_at = VALUES(opens_at),
                   closes_at = VALUES(closes_at),
                   is_closed = VALUES(is_closed)',
                [$restaurantId, $day, $open . ':00', $close . ':00', $isClosed]
            );
        }

        if (!$errors) {
            flash('Opening hours saved.', 'success');
            redirect('admin/hours.php');
        }
    }
}

$hours      = restaurant_hours($restaurantId);
$usesSchedule = (int) $restaurant['uses_schedule'] === 1;
$openNow    = restaurant_within_hours($restaurant);
$summary    = weekly_hours_summary($restaurantId);

include __DIR__ . '/../includes/panel_header.php';
?>

<div class="card" style="margin-bottom:1.5rem;
     border-left:4px solid var(--<?= $usesSchedule ? 'ok' : 'muted' ?>)">
  <div class="row row--between">
    <div>
      <h2 class="card__title" style="margin-bottom:.25rem">
        <?= $usesSchedule ? 'Ordering follows these hours' : 'Ordering ignores these hours' ?>
      </h2>
      <p class="text-muted" style="margin:0;font-size:.9rem">
        <?php if ($usesSchedule): ?>
          Customers can order between the times below and see a closed notice
          outside them. Right now <?= e($restaurant['name']) ?> is
          <strong><?= $openNow ? 'inside' : 'outside' ?></strong> its opening hours.
        <?php else: ?>
          The times below are shown to customers but do not stop anyone
          ordering. Only the accepting-orders switch does that.
        <?php endif; ?>
      </p>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="toggle_schedule">
      <button class="btn <?= $usesSchedule ? 'btn--ghost' : 'btn--accent' ?>" type="submit"
              <?= $usesSchedule ? '' : 'data-confirm="Close ordering automatically outside these hours?"' ?>>
        <?= $usesSchedule ? 'Stop following the schedule' : 'Follow this schedule' ?>
      </button>
    </form>
  </div>
</div>

<?php if ($usesSchedule && !$openNow): ?>
  <div class="alert alert--info" role="status">
    <?= e(todays_hours_line($restaurant)) ?>. Customers can browse the menu but
    cannot place an order until then.
  </div>
<?php endif; ?>

<div class="grid grid--2" style="align-items:start">

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_hours">

    <div class="card">
      <h2 class="card__title">The week</h2>
      <p class="text-muted" style="margin-top:-.5rem;font-size:.9rem">
        A closing time earlier than the opening time means the shift runs past
        midnight, so 17:00 to 01:00 is a normal evening service.
      </p>

      <div class="table-scroll">
        <table class="data" style="box-shadow:none">
          <thead>
            <tr><th>Day</th><th>Opens</th><th>Closes</th><th>Closed</th></tr>
          </thead>
          <tbody>
            <?php for ($day = 0; $day <= 6; $day++): ?>
              <?php $row = $hours[$day]; $isClosed = (int) $row['is_closed'] === 1; ?>
              <tr>
                <td>
                  <strong><?= e($names[$day]) ?></strong>
                  <?php if ($day === today_index()): ?>
                    <span class="badge">Today</span>
                  <?php endif; ?>
                  <?php if (isset($errors[$day])): ?>
                    <br><span class="field__error"><?= e($errors[$day]) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <label class="sr-only" for="open-<?= $day ?>">
                    <?= e($names[$day]) ?> opening time
                  </label>
                  <input type="time" id="open-<?= $day ?>" name="opens_at[<?= $day ?>]"
                         value="<?= e(pretty_time($row['opens_at'])) ?>"
                         <?= $isClosed ? 'readonly' : '' ?>>
                </td>
                <td>
                  <label class="sr-only" for="close-<?= $day ?>">
                    <?= e($names[$day]) ?> closing time
                  </label>
                  <input type="time" id="close-<?= $day ?>" name="closes_at[<?= $day ?>]"
                         value="<?= e(pretty_time($row['closes_at'])) ?>"
                         <?= $isClosed ? 'readonly' : '' ?>>
                </td>
                <td>
                  <label style="display:flex;align-items:center;gap:.4rem;font-weight:400">
                    <input type="checkbox" name="is_closed[<?= $day ?>]" value="1"
                           style="width:auto" data-closed-toggle="<?= $day ?>"
                           <?= $isClosed ? 'checked' : '' ?>>
                    <span class="sr-only">Closed on <?= e($names[$day]) ?></span>
                  </label>
                </td>
              </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>

      <div class="row" style="margin-top:1.25rem">
        <button class="btn btn--accent" type="submit">Save opening hours</button>
      </div>
    </div>
  </form>

  <div class="card">
    <h2 class="card__title">How customers see it</h2>

    <p style="font-weight:600;margin-bottom:.75rem">
      <?= e(todays_hours_line($restaurant)) ?>
    </p>

    <table class="data" style="box-shadow:none">
      <tbody>
        <?php foreach ($summary as $line): ?>
          <tr>
            <th style="white-space:nowrap"><?= e($line['days']) ?></th>
            <td class="text-right"><?= e($line['hours']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <p class="text-muted" style="font-size:.86rem;margin-top:1rem">
      Consecutive days with the same times are grouped, which is how a
      restaurant writes it on its own door.
    </p>

    <hr style="border:none;border-top:1px solid var(--line);margin:1.25rem 0">

    <h3 style="font-size:1rem;margin-bottom:.5rem">What overrides what</h3>
    <ol class="stack" style="padding-left:1.1rem;font-size:.9rem">
      <li>The platform pause closes every restaurant.</li>
      <li>Suspension by Smart Eats closes this one.</li>
      <li>Your accepting-orders switch closes this one immediately.</li>
      <li>This schedule closes it outside the hours above.</li>
    </ol>
    <p class="text-muted" style="font-size:.86rem">
      Any one of them being closed is enough. The schedule is for the routine
      week; the switch is for the evening something breaks.
    </p>
  </div>

</div>

<script>
  // Marking the times read-only on a closed day makes the row read
  // correctly at a glance. Read-only rather than disabled on purpose: a
  // disabled field is not submitted, so a closed day would come back
  // with its stored times wiped and the owner would lose them the moment
  // they reopened.
  document.querySelectorAll('[data-closed-toggle]').forEach(function (box) {
    box.addEventListener('change', function () {
      var day = box.dataset.closedToggle;
      ['open-', 'close-'].forEach(function (prefix) {
        var field = document.getElementById(prefix + day);
        if (field) field.readOnly = box.checked;
      });
    });
  });
</script>

<?php include __DIR__ . '/../includes/panel_footer.php'; ?>
