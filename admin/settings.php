<?php
/**
 * Smart Eats - restaurant settings
 *
 * Everything here used to be a row in the platform-wide `settings`
 * table. On a platform of independent businesses that was wrong: two
 * restaurants cannot share one delivery fee, one minimum order or one
 * set of opening hours. These are now columns on the restaurant's own
 * row, and this screen edits that row.
 *
 * The most important control is still the accepting-orders switch: a
 * small restaurant that suddenly cannot cook needs to stop taking orders
 * in one click, not by asking someone to run an UPDATE.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/restaurants.php';

require_role('admin', 'vendor');
require_active_restaurant();

// The sidebar switcher posts back to this page, so it is dealt with
// before this screen's own POST handling sees an action it cannot place.
handle_restaurant_switch();

global $CUISINES;

$restaurant   = require_panel_restaurant();
$restaurantId = (int) $restaurant['id'];

$page_title = 'Settings';
$errors     = [];

// Field definitions drive both validation and the form, so adding a
// setting means adding one row rather than editing three places.
$fields = [
    'name'               => ['label' => 'Restaurant name', 'type' => 'text',    'group' => 'About'],
    'tagline'            => ['label' => 'Tagline',         'type' => 'text',    'group' => 'About'],
    'cuisine'            => ['label' => 'Cuisine',         'type' => 'cuisine', 'group' => 'About'],
    'description'        => ['label' => 'Description',     'type' => 'textarea','group' => 'About',
                             'hint'  => 'Shown on your restaurant page.'],
    'phone'              => ['label' => 'Phone number',    'type' => 'text',    'group' => 'Contact'],
    'email'              => ['label' => 'Email address',   'type' => 'email',   'group' => 'Contact'],
    'address'            => ['label' => 'Address',         'type' => 'text',    'group' => 'Contact'],
    'city'               => ['label' => 'Town or city',    'type' => 'text',    'group' => 'Contact'],
    'opening_hours'      => ['label' => 'Opening hours',   'type' => 'text',    'group' => 'Contact'],
    'delivery_fee'       => ['label' => 'Delivery fee',    'type' => 'money',   'group' => 'Ordering'],
    'free_delivery_over' => ['label' => 'Free delivery over', 'type' => 'money','group' => 'Ordering',
                             'hint'  => 'Set to 0 to always charge the delivery fee.'],
    'min_order_value'    => ['label' => 'Minimum order',   'type' => 'money',   'group' => 'Ordering'],
    'tax_rate'           => ['label' => 'VAT rate',        'type' => 'rate',    'group' => 'Ordering',
                             'hint'  => '0 for prices that already include VAT, or 0.20 for 20 per cent added.'],
    'delivery_postcodes' => ['label' => 'Delivery postcodes', 'type' => 'text',  'group' => 'Ordering',
                             'hint'  => 'Outward codes you deliver to, separated by commas, e.g. E1, E2, N1. '
                                      . 'Leave blank to deliver anywhere. Collection is never restricted.'],
];

// Commission belongs to the platform, not to the restaurant, so a vendor
// can see the rate they are on but cannot edit it.
if (has_role('admin')) {
    $fields['commission_rate'] = [
        'label' => 'Platform commission',
        'type'  => 'rate',
        'group' => 'Ordering',
        'hint'  => 'As a decimal: 0 for none, 0.10 for ten per cent. '
                 . 'Applied to card payments in the settlement report.',
    ];
}

$values = [];
foreach ($fields as $key => $field) {
    $values[$key] = (string) ($restaurant[$key] ?? '');
}

$accepting = (int) $restaurant['is_accepting_orders'] === 1;

if (is_post()) {
    verify_csrf();

    /* ---------------- The one-click pause ---------------- */
    if (input('action') === 'toggle_orders') {
        $now = $accepting ? 0 : 1;
        db_run(
            'UPDATE restaurants SET is_accepting_orders = ? WHERE id = ?',
            [$now, $restaurantId]
        );
        flash(
            $now
                ? $restaurant['name'] . ' is accepting orders again.'
                : 'Online ordering is paused. Customers can browse but not order.',
            'success'
        );
        redirect('admin/settings.php');
    }

    /* ---------------- The logo ---------------- */
    if (input('action') === 'remove_logo') {
        if ($restaurant['logo']) {
            delete_restaurant_logo($restaurant['logo']);
            db_run('UPDATE restaurants SET logo = NULL WHERE id = ?', [$restaurantId]);
        }
        flash('Logo removed.', 'success');
        redirect('admin/settings.php');
    }

    /* ---------------- Everything else ---------------- */
    foreach ($fields as $key => $field) {
        $values[$key] = input($key);

        switch ($field['type']) {
            case 'money':
                if ($values[$key] === '' || !is_numeric($values[$key])) {
                    $errors[$key] = 'Enter an amount, for example 2.50.';
                } elseif ((float) $values[$key] < 0) {
                    $errors[$key] = 'This cannot be negative.';
                }
                break;

            case 'rate':
                if ($values[$key] === '' || !is_numeric($values[$key])) {
                    $errors[$key] = 'Enter a rate, for example 0.20.';
                } elseif ((float) $values[$key] < 0 || (float) $values[$key] > 1) {
                    $errors[$key] = 'Enter the rate as a decimal between 0 and 1.';
                }
                break;

            case 'email':
                if ($values[$key] !== '' && !is_valid_email($values[$key])) {
                    $errors[$key] = 'That does not look like a valid email address.';
                }
                break;

            case 'cuisine':
                if ($values[$key] !== '' && !in_array($values[$key], $CUISINES, true)) {
                    $errors[$key] = 'Choose one of the listed cuisines.';
                }
                break;

            case 'textarea':
                if (str_len($values[$key]) > 1000) {
                    $errors[$key] = 'Keep this under 1000 characters.';
                }
                break;

            default:
                if ($key === 'name' && $values[$key] === '') {
                    $errors[$key] = 'Your restaurant needs a name.';
                }
                if (str_len($values[$key]) > 255) {
                    $errors[$key] = 'Keep this under 255 characters.';
                }
        }
    }

    // An optional new logo.
    $newLogo = null;
    $hasUpload = isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($hasUpload) {
        $upload = store_restaurant_logo($_FILES['logo']);
        if ($upload['ok']) {
            $newLogo = $upload['filename'];
        } else {
            $errors['logo'] = $upload['error'];
        }
    }

    if (!$errors) {
        // The slug follows the name, but only when the name changes, so
        // an existing restaurant's saved links do not break on every save.
        $slug = $values['name'] !== $restaurant['name']
            ? unique_restaurant_slug($values['name'], $restaurantId)
            : $restaurant['slug'];

        // The SET clause is built from the same $fields definition that
        // drove validation and the form. Adding a setting is then one
        // line in one place, rather than three that can drift apart.
        $columns = ['`slug` = ?'];
        $bind    = [$slug];

        foreach ($fields as $key => $field) {
            $columns[] = '`' . $key . '` = ?';

            // Numbers are stored as given; empty text becomes NULL, so a
            // cleared field reads as "not set" rather than as an empty
            // string that has to be tested for everywhere else.
            $bind[] = in_array($field['type'], ['money', 'rate'], true)
                ? $values[$key]
                : ($values[$key] !== '' ? $values[$key] : null);
        }

        if ($newLogo) {
            $columns[] = '`logo` = ?';
            $bind[]    = $newLogo;
        }

        $bind[] = $restaurantId;

        db_run(
            'UPDATE `restaurants` SET ' . implode(', ', $columns) . ' WHERE id = ?',
            $bind
        );

        if ($newLogo && $restaurant['logo']) {
            delete_restaurant_logo($restaurant['logo']);
        }

        flash('Settings saved.', 'success');
        redirect('admin/settings.php');
    }
}

$groups = [];
foreach ($fields as $key => $field) {
    $groups[$field['group']][$key] = $field;
}

$logoUrl = restaurant_logo_url($restaurant['logo']);

include __DIR__ . '/../includes/panel_header.php';
?>

<?php if ($restaurant['approval_status'] !== 'approved'): ?>
  <div class="alert alert--warning" role="status">
    <?= e($restaurant['name']) ?> is <?= e($restaurant['approval_status']) ?>.
    These settings are saved and take effect as soon as the restaurant is live.
  </div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem;
     border-left:4px solid var(--<?= $accepting ? 'ok' : 'err' ?>)">
  <div class="row row--between">
    <div>
      <h2 class="card__title" style="margin-bottom:.25rem">
        <?= $accepting ? 'Accepting orders' : 'Ordering is paused' ?>
      </h2>
      <p class="text-muted" style="margin:0;font-size:.9rem">
        <?= $accepting
            ? 'Customers can browse your menu and place orders as normal.'
            : 'Customers can still browse your menu, but the basket and checkout are closed.' ?>
      </p>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="toggle_orders">
      <button class="btn <?= $accepting ? 'btn--ghost' : 'btn--accent' ?>" type="submit"
              <?= $accepting ? 'data-confirm="Stop taking online orders?"' : '' ?>>
        <?= $accepting ? 'Pause ordering' : 'Start accepting orders' ?>
      </button>
    </form>
  </div>
</div>

<form method="post" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>

  <div class="grid grid--2" style="align-items:start">
    <?php foreach ($groups as $groupName => $groupFields): ?>
      <div class="card">
        <h2 class="card__title"><?= e($groupName) ?></h2>

        <?php foreach ($groupFields as $key => $field): ?>
          <div class="<?= field_class($errors, $key) ?>">
            <label for="<?= e($key) ?>"><?= e($field['label']) ?></label>

            <?php if ($field['type'] === 'textarea'): ?>
              <textarea id="<?= e($key) ?>" name="<?= e($key) ?>" rows="3"><?= e($values[$key]) ?></textarea>

            <?php elseif ($field['type'] === 'cuisine'): ?>
              <select id="<?= e($key) ?>" name="<?= e($key) ?>">
                <option value="">Not set</option>
                <?php foreach ($CUISINES as $cuisine): ?>
                  <option value="<?= e($cuisine) ?>" <?= $values[$key] === $cuisine ? 'selected' : '' ?>>
                    <?= e($cuisine) ?>
                  </option>
                <?php endforeach; ?>
              </select>

            <?php else: ?>
              <input type="<?= $field['type'] === 'email' ? 'email' : 'text' ?>"
                     id="<?= e($key) ?>" name="<?= e($key) ?>"
                     value="<?= e($values[$key]) ?>"
                     <?= in_array($field['type'], ['money', 'rate'], true) ? 'inputmode="decimal"' : '' ?>>
            <?php endif; ?>

            <?php if (!empty($field['hint'])): ?>
              <span class="field__hint"><?= e($field['hint']) ?></span>
            <?php endif; ?>
            <?= field_error($errors, $key) ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <div class="card">
      <h2 class="card__title">Logo</h2>

      <?php if ($logoUrl): ?>
        <img src="<?= e($logoUrl) ?>" alt="<?= e($restaurant['name']) ?> logo"
             style="width:120px;height:120px;object-fit:cover;border-radius:var(--radius);margin-bottom:1rem">
      <?php else: ?>
        <p class="text-muted" style="font-size:.9rem">
          No logo uploaded. Your restaurant shows the initials
          <strong><?= e(restaurant_monogram($restaurant['name'])) ?></strong> instead.
        </p>
      <?php endif; ?>

      <div class="<?= field_class($errors, 'logo') ?>">
        <label for="logo"><?= $logoUrl ? 'Replace logo' : 'Upload a logo' ?></label>
        <input type="file" id="logo" name="logo" accept="image/jpeg,image/png,image/webp">
        <span class="field__hint">
          JPG, PNG or WebP, under <?= round(MAX_UPLOAD_BYTES / 1048576, 1) ?> MB.
          A square image around 400 by 400 pixels works best.
        </span>
        <?= field_error($errors, 'logo') ?>
      </div>
    </div>
  </div>

  <div class="row" style="margin-top:1.5rem">
    <button class="btn btn--accent btn--lg" type="submit">Save settings</button>
    <a class="btn btn--ghost btn--lg" href="<?= url('admin/dashboard.php') ?>">Cancel</a>
  </div>

  <p class="text-muted" style="font-size:.86rem;margin-top:1rem">
    These values appear on your restaurant page and in the totals calculated at
    checkout. Changing the delivery fee or minimum order affects new baskets
    immediately; orders already placed keep the figures they were charged.
  </p>
</form>

<?php if ($restaurant['logo']): ?>
  <form method="post" style="margin-top:1rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="remove_logo">
    <button class="link-button" type="submit" data-confirm="Remove the logo?">Remove logo</button>
  </form>
<?php endif; ?>

<?php include __DIR__ . '/../includes/panel_footer.php'; ?>
