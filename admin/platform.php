<?php
/**
 * Smart Eats - platform settings
 *
 * The handful of values that genuinely belong to the whole platform
 * rather than to any one restaurant: the name in the header, the support
 * contact details, the currency and the maintenance switch.
 *
 * Everything a restaurant sets for itself - fees, minimum order, VAT,
 * opening hours, address - lives on the restaurants table and is edited
 * on the restaurant's own settings screen. Keeping the two apart is what
 * stops one business's change from altering another's prices.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restaurants.php';

require_role('admin');

// The sidebar switcher posts back to this page, so it is dealt with
// before this screen's own POST handling sees an action it cannot place.
handle_restaurant_switch();

$page_title = 'Platform settings';
$errors     = [];

$fields = [
    'platform_name'    => ['label' => 'Platform name',    'type' => 'text',  'group' => 'Branding'],
    'platform_tagline' => ['label' => 'Tagline',          'type' => 'text',  'group' => 'Branding'],
    'platform_email'   => ['label' => 'Support email',    'type' => 'email', 'group' => 'Contact'],
    'platform_phone'   => ['label' => 'Support phone',    'type' => 'text',  'group' => 'Contact'],
    'currency_symbol'  => ['label' => 'Currency symbol',  'type' => 'text',  'group' => 'Money'],
    'currency_code'    => ['label' => 'Currency code',    'type' => 'text',  'group' => 'Money',
                           'hint'  => 'Three letters, for example GBP. Used when charging cards.'],
];

$values = [];
foreach ($fields as $key => $field) {
    $values[$key] = setting($key, '');
}

$open = platform_is_open();

if (is_post()) {
    verify_csrf();

    if (input('action') === 'toggle_platform') {
        $now = $open ? '0' : '1';
        db_run(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            ['platform_open', $now]
        );
        flash(
            $now === '1'
                ? 'Ordering has been restored across the platform.'
                : 'Ordering is paused for every restaurant. Browsing still works.',
            $now === '1' ? 'success' : 'warning'
        );
        redirect('admin/platform.php');
    }

    foreach ($fields as $key => $field) {
        $values[$key] = input($key);

        if ($field['type'] === 'email') {
            if ($values[$key] !== '' && !is_valid_email($values[$key])) {
                $errors[$key] = 'That does not look like a valid email address.';
            }
        }

        if ($key === 'platform_name' && $values[$key] === '') {
            $errors[$key] = 'The platform needs a name.';
        }

        if ($key === 'currency_code' && !preg_match('/^[A-Za-z]{3}$/', $values[$key])) {
            $errors[$key] = 'Use a three letter currency code, for example GBP.';
        }

        if (str_len($values[$key]) > 255) {
            $errors[$key] = 'Keep this under 255 characters.';
        }
    }

    if (!$errors) {
        foreach ($values as $key => $value) {
            if ($key === 'currency_code') {
                $value = strtoupper($value);
            }
            db_run(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                [$key, $value]
            );
        }

        flash('Platform settings saved.', 'success');
        redirect('admin/platform.php');
    }
}

$groups = [];
foreach ($fields as $key => $field) {
    $groups[$field['group']][$key] = $field;
}

include __DIR__ . '/../includes/panel_header.php';
?>

<div class="card" style="margin-bottom:1.5rem;
     border-left:4px solid var(--<?= $open ? 'ok' : 'err' ?>)">
  <div class="row row--between">
    <div>
      <h2 class="card__title" style="margin-bottom:.25rem">
        <?= $open ? 'The platform is open' : 'Ordering is paused platform-wide' ?>
      </h2>
      <p class="text-muted" style="margin:0;font-size:.9rem">
        <?= $open
            ? 'Every approved restaurant that is accepting orders can be ordered from.'
            : 'No restaurant can take an order, whatever its own setting says. '
              . 'Customers can still browse menus and track existing orders.' ?>
      </p>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="toggle_platform">
      <button class="btn <?= $open ? 'btn--ghost' : 'btn--accent' ?>" type="submit"
              <?= $open
                  ? 'data-confirm="Stop ordering across every restaurant on the platform?"'
                  : '' ?>>
        <?= $open ? 'Pause the platform' : 'Reopen the platform' ?>
      </button>
    </form>
  </div>
</div>

<form method="post" novalidate>
  <?= csrf_field() ?>

  <div class="grid grid--2" style="align-items:start">
    <?php foreach ($groups as $groupName => $groupFields): ?>
      <div class="card">
        <h2 class="card__title"><?= e($groupName) ?></h2>

        <?php foreach ($groupFields as $key => $field): ?>
          <div class="<?= field_class($errors, $key) ?>">
            <label for="<?= e($key) ?>"><?= e($field['label']) ?></label>
            <input type="<?= $field['type'] === 'email' ? 'email' : 'text' ?>"
                   id="<?= e($key) ?>" name="<?= e($key) ?>"
                   value="<?= e($values[$key]) ?>">
            <?php if (!empty($field['hint'])): ?>
              <span class="field__hint"><?= e($field['hint']) ?></span>
            <?php endif; ?>
            <?= field_error($errors, $key) ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="row" style="margin-top:1.5rem">
    <button class="btn btn--accent btn--lg" type="submit">Save platform settings</button>
    <a class="btn btn--ghost btn--lg" href="<?= url('admin/dashboard.php') ?>">Cancel</a>
  </div>

  <p class="text-muted" style="font-size:.86rem;margin-top:1rem">
    Delivery fees, minimum orders, VAT rates, addresses and opening hours belong
    to individual restaurants and are edited on
    <a href="<?= url('admin/settings.php') ?>">the restaurant settings screen</a>,
    one restaurant at a time.
  </p>
</form>

<?php include __DIR__ . '/../includes/panel_footer.php'; ?>
