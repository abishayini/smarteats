<?php
/**
 * Smart Eats - list your restaurant
 *
 * FR-20 restaurant registration.
 *
 * The public sign-up route for a restaurant. One submission creates two
 * things inside a single transaction: the owner's account and their
 * restaurant. Doing it in a transaction matters, because an owner
 * account with no restaurant would be an account that can sign in and
 * see nothing, and a restaurant with no owner could never be managed.
 *
 * The restaurant is created as `pending`. It cannot be found, ordered
 * from, or reached by direct link until a platform administrator
 * approves it, which stops the public form from putting a live menu on
 * the site. The owner may sign in immediately and build their menu,
 * which is the sensible use of the waiting period.
 *
 * The role is never read from the form. It is always 'vendor', so this
 * page cannot be used to create an administrator.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/restaurants.php';

global $CUISINES;

if (is_logged_in() && current_user()) {
    flash('You are already signed in. Sign out first to register a new restaurant.', 'info');
    redirect(home_for_role(current_role()));
}

$page_title = 'List your restaurant';
$errors     = [];
$values     = [
    'restaurant_name' => '',
    'cuisine'         => '',
    'address'         => '',
    'city'            => '',
    'phone'           => '',
    'opening_hours'   => '',
    'description'     => '',
    'owner_name'      => '',
    'email'           => '',
    'delivery_fee'    => '2.50',
    'min_order_value' => '10.00',
];

if (is_post()) {
    verify_csrf();

    foreach (array_keys($values) as $key) {
        $values[$key] = input($key);
    }
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    /* ---------------- The restaurant ---------------- */

    if ($values['restaurant_name'] === '') {
        $errors['restaurant_name'] = 'Enter the name of your restaurant.';
    } elseif (str_len($values['restaurant_name']) > 120) {
        $errors['restaurant_name'] = 'Keep the name under 120 characters.';
    } elseif (make_slug($values['restaurant_name']) === '') {
        $errors['restaurant_name'] = 'Use at least one letter or number in the name.';
    }

    if ($values['cuisine'] === '' || !in_array($values['cuisine'], $CUISINES, true)) {
        $errors['cuisine'] = 'Choose the closest cuisine.';
    }

    if ($values['address'] === '') {
        $errors['address'] = 'Enter the address customers collect from.';
    }

    if ($values['phone'] === '') {
        $errors['phone'] = 'Enter a phone number for your restaurant.';
    } elseif (!is_valid_phone($values['phone'])) {
        $errors['phone'] = 'Enter a valid phone number.';
    }

    foreach (['delivery_fee' => 'Delivery fee', 'min_order_value' => 'Minimum order'] as $key => $label) {
        if ($values[$key] === '' || !is_numeric($values[$key]) || (float) $values[$key] < 0) {
            $errors[$key] = 'Enter an amount, for example 2.50.';
        } elseif ((float) $values[$key] > 100) {
            $errors[$key] = 'That looks wrong. The maximum here is 100.';
        }
    }

    /* ---------------- The owner ---------------- */

    if ($values['owner_name'] === '') {
        $errors['owner_name'] = 'Enter your name.';
    }

    if ($values['email'] === '') {
        $errors['email'] = 'Enter your email address.';
    } elseif (!is_valid_email($values['email'])) {
        $errors['email'] = 'That does not look like a valid email address.';
    } elseif (db_value('SELECT 1 FROM users WHERE email = ?', [strtolower($values['email'])])) {
        $errors['email'] = 'An account already uses this address. Sign in instead.';
    }

    if ($problem = password_problem($password)) {
        $errors['password'] = $problem;
    }

    if ($password !== $confirm) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }

    /* ---------------- Write both, or neither ---------------- */

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $userId = db_insert(
                'INSERT INTO users (full_name, email, password_hash, phone, role)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $values['owner_name'],
                    strtolower($values['email']),
                    password_hash($password, PASSWORD_DEFAULT),
                    $values['phone'],
                    'vendor',           // never taken from the form
                ]
            );

            $restaurantId = db_insert(
                'INSERT INTO restaurants
                   (name, slug, owner_user_id, cuisine, description, phone, email,
                    address, city, opening_hours, delivery_fee, min_order_value,
                    approval_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $values['restaurant_name'],
                    unique_restaurant_slug($values['restaurant_name']),
                    $userId,
                    $values['cuisine'],
                    $values['description'] !== '' ? $values['description'] : null,
                    $values['phone'],
                    strtolower($values['email']),
                    $values['address'],
                    $values['city'] !== '' ? $values['city'] : null,
                    $values['opening_hours'] !== '' ? $values['opening_hours'] : null,
                    $values['delivery_fee'],
                    $values['min_order_value'],
                    REQUIRE_RESTAURANT_APPROVAL ? 'pending' : 'approved',
                ]
            );

            db_run(
                'UPDATE users SET restaurant_id = ? WHERE id = ?',
                [$restaurantId, $userId]
            );

            $pdo->commit();

        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Restaurant registration failed: ' . $e->getMessage());
            $errors['form'] = 'The registration could not be completed. Please try again.';
        }

        if (!isset($errors['form'])) {
            login_user([
                'id'        => $userId,
                'full_name' => $values['owner_name'],
                'role'      => 'vendor',
            ]);

            flash(
                REQUIRE_RESTAURANT_APPROVAL
                    ? $values['restaurant_name'] . ' has been registered and is waiting for approval. '
                      . 'You can build your menu now, and it goes live as soon as Smart Eats approves it.'
                    : $values['restaurant_name'] . ' is live. Start by adding your categories and dishes.',
                'success'
            );
            redirect('admin/dashboard.php');
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap" style="max-width:820px">

    <h1>List your restaurant on Smart Eats</h1>
    <p class="text-muted" style="max-width:60ch">
      Smart Eats takes no commission. You keep your own menu, your own prices
      and your own customers; orders arrive on a kitchen screen instead of over
      the phone. Registration takes a minute, and your restaurant goes live once
      we have checked the details.
    </p>

    <?php if (isset($errors['form'])): ?>
      <div class="alert alert--error" role="alert"><?= e($errors['form']) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= url('restaurant_register.php') ?>" data-once novalidate>
      <?= csrf_field() ?>

      <div class="card" style="margin-bottom:1.5rem">
        <h2 class="card__title">About the restaurant</h2>

        <div class="<?= field_class($errors, 'restaurant_name') ?>">
          <label for="restaurant_name">Restaurant name</label>
          <input type="text" id="restaurant_name" name="restaurant_name"
                 value="<?= e($values['restaurant_name']) ?>" required autofocus>
          <span class="field__hint">This is what customers see on the directory.</span>
          <?= field_error($errors, 'restaurant_name') ?>
        </div>

        <div class="<?= field_class($errors, 'cuisine') ?>">
          <label for="cuisine">Cuisine</label>
          <select id="cuisine" name="cuisine" required>
            <option value="">Choose one</option>
            <?php foreach ($CUISINES as $cuisine): ?>
              <option value="<?= e($cuisine) ?>" <?= $values['cuisine'] === $cuisine ? 'selected' : '' ?>>
                <?= e($cuisine) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?= field_error($errors, 'cuisine') ?>
        </div>

        <div class="<?= field_class($errors, 'address') ?>">
          <label for="address">Address</label>
          <input type="text" id="address" name="address" value="<?= e($values['address']) ?>" required>
          <span class="field__hint">Shown to customers collecting an order.</span>
          <?= field_error($errors, 'address') ?>
        </div>

        <div class="row" style="gap:1rem;align-items:flex-start">
          <div class="field" style="flex:1">
            <label for="city">Town or city</label>
            <input type="text" id="city" name="city" value="<?= e($values['city']) ?>">
          </div>

          <div class="<?= field_class($errors, 'phone') ?>" style="flex:1">
            <label for="phone">Restaurant phone</label>
            <input type="tel" id="phone" name="phone" value="<?= e($values['phone']) ?>" required>
            <?= field_error($errors, 'phone') ?>
          </div>
        </div>

        <div class="field">
          <label for="opening_hours">Opening hours</label>
          <input type="text" id="opening_hours" name="opening_hours"
                 value="<?= e($values['opening_hours']) ?>"
                 placeholder="Tue to Sun, 12:00 to 23:00">
        </div>

        <div class="field">
          <label for="description">Short description <span class="text-muted">(optional)</span></label>
          <textarea id="description" name="description" rows="3"
                    placeholder="What you cook and what makes it worth ordering."><?= e($values['description']) ?></textarea>
        </div>
      </div>

      <div class="card" style="margin-bottom:1.5rem">
        <h2 class="card__title">Your ordering rules</h2>
        <p class="text-muted" style="margin-top:-.5rem;font-size:.9rem">
          These are yours alone and can be changed at any time from your
          settings screen. Other restaurants set their own.
        </p>

        <div class="row" style="gap:1rem;align-items:flex-start">
          <div class="<?= field_class($errors, 'delivery_fee') ?>" style="flex:1">
            <label for="delivery_fee">Delivery fee (<?= e(setting('currency_symbol', '£')) ?>)</label>
            <input type="number" id="delivery_fee" name="delivery_fee"
                   value="<?= e($values['delivery_fee']) ?>" step="0.01" min="0" max="100" required>
            <span class="field__hint">Enter 0 if you deliver free.</span>
            <?= field_error($errors, 'delivery_fee') ?>
          </div>

          <div class="<?= field_class($errors, 'min_order_value') ?>" style="flex:1">
            <label for="min_order_value">Minimum order (<?= e(setting('currency_symbol', '£')) ?>)</label>
            <input type="number" id="min_order_value" name="min_order_value"
                   value="<?= e($values['min_order_value']) ?>" step="0.01" min="0" max="100" required>
            <span class="field__hint">Enter 0 for no minimum.</span>
            <?= field_error($errors, 'min_order_value') ?>
          </div>
        </div>
      </div>

      <div class="card">
        <h2 class="card__title">Your owner account</h2>
        <p class="text-muted" style="margin-top:-.5rem;font-size:.9rem">
          You will use this to manage your menu, your staff and your orders.
        </p>

        <div class="<?= field_class($errors, 'owner_name') ?>">
          <label for="owner_name">Your name</label>
          <input type="text" id="owner_name" name="owner_name"
                 value="<?= e($values['owner_name']) ?>" autocomplete="name" required>
          <?= field_error($errors, 'owner_name') ?>
        </div>

        <div class="<?= field_class($errors, 'email') ?>">
          <label for="email">Email address</label>
          <input type="email" id="email" name="email" value="<?= e($values['email']) ?>"
                 autocomplete="email" required>
          <?= field_error($errors, 'email') ?>
        </div>

        <div class="<?= field_class($errors, 'password') ?>">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" autocomplete="new-password" required>
          <span class="field__hint">
            At least <?= PASSWORD_MIN_LENGTH ?> characters, including a letter and a number.
          </span>
          <?= field_error($errors, 'password') ?>
        </div>

        <div class="<?= field_class($errors, 'password_confirm') ?>">
          <label for="password_confirm">Confirm password</label>
          <input type="password" id="password_confirm" name="password_confirm"
                 autocomplete="new-password" required>
          <?= field_error($errors, 'password_confirm') ?>
        </div>

        <button class="btn btn--accent btn--full btn--lg" type="submit">
          Register my restaurant
        </button>

        <p class="text-muted" style="font-size:.84rem;margin:1rem 0 0">
          <?php if (REQUIRE_RESTAURANT_APPROVAL): ?>
            Your restaurant is reviewed by Smart Eats before customers can see
            it. You can sign in and build your menu straight away.
          <?php else: ?>
            Your restaurant goes live as soon as you add your first dishes.
          <?php endif; ?>
        </p>
      </div>
    </form>

    <p style="margin-top:1.5rem">
      Already listed? <a href="<?= url('login.php') ?>">Sign in to your restaurant</a>.
    </p>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
