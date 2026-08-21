<?php
/**
 * Smart Eats - create a customer account
 *
 * Registration here is for customers only. The role is never taken from
 * the submitted form, so this page cannot be used to create a staff,
 * vendor or administrator account. Restaurant owners register through
 * restaurant_register.php, which creates an account and a restaurant
 * together; staff accounts are created by their own restaurant.
 */

require_once __DIR__ . '/includes/auth.php';

if (is_logged_in() && current_user()) {
    redirect(home_for_role(current_role()));
}

$page_title = 'Create an account';
$errors     = [];
$values     = ['full_name' => '', 'email' => '', 'phone' => '', 'address' => ''];

if (is_post()) {
    verify_csrf();

    $values['full_name'] = input('full_name');
    $values['email']     = strtolower(input('email'));
    $values['phone']     = input('phone');
    $values['address']   = input('address');
    $password            = $_POST['password'] ?? '';
    $confirm             = $_POST['password_confirm'] ?? '';

    if ($values['full_name'] === '') {
        $errors['full_name'] = 'Enter your name.';
    } elseif (str_len($values['full_name']) > 120) {
        $errors['full_name'] = 'Keep this under 120 characters.';
    }

    if ($values['email'] === '') {
        $errors['email'] = 'Enter your email address.';
    } elseif (!is_valid_email($values['email'])) {
        $errors['email'] = 'That does not look like a valid email address.';
    } elseif (db_value('SELECT 1 FROM users WHERE email = ?', [$values['email']])) {
        $errors['email'] = 'An account already uses this address. Try signing in instead.';
    }

    if ($values['phone'] === '') {
        $errors['phone'] = 'Enter a contact number so the kitchen can reach you.';
    } elseif (!is_valid_phone($values['phone'])) {
        $errors['phone'] = 'Enter a valid phone number.';
    }

    if ($problem = password_problem($password)) {
        $errors['password'] = $problem;
    }

    if ($password !== $confirm) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }

    if (!$errors) {
        $userId = db_insert(
            'INSERT INTO users (full_name, email, password_hash, phone, address, role)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $values['full_name'],
                $values['email'],
                password_hash($password, PASSWORD_DEFAULT),
                $values['phone'],
                $values['address'] !== '' ? $values['address'] : null,
                'customer',
            ]
        );

        // Sign the new customer straight in; making them log in again
        // immediately after registering is an avoidable extra step.
        login_user([
            'id'        => $userId,
            'full_name' => $values['full_name'],
            'role'      => 'customer',
        ]);

        flash('Welcome, ' . explode(' ', $values['full_name'])[0] . '. Your account is ready.', 'success');
        redirect('restaurants.php');
    }
}

include __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap" style="max-width:560px">

    <h1>Create an account</h1>
    <p class="text-muted">
      An account saves your details for next time and keeps a record of every
      order across all the restaurants you order from. You can also order
      without one.
    </p>

    <form class="card" method="post" action="<?= url('register.php') ?>" data-once novalidate>
      <?= csrf_field() ?>

      <div class="<?= field_class($errors, 'full_name') ?>">
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name"
               value="<?= e($values['full_name']) ?>"
               autocomplete="name" required autofocus>
        <?= field_error($errors, 'full_name') ?>
      </div>

      <div class="<?= field_class($errors, 'email') ?>">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email"
               value="<?= e($values['email']) ?>"
               autocomplete="email" required>
        <?= field_error($errors, 'email') ?>
      </div>

      <div class="<?= field_class($errors, 'phone') ?>">
        <label for="phone">Phone number</label>
        <input type="tel" id="phone" name="phone"
               value="<?= e($values['phone']) ?>"
               autocomplete="tel" required>
        <span class="field__hint">Shared with a restaurant only when you order from it.</span>
        <?= field_error($errors, 'phone') ?>
      </div>

      <div class="<?= field_class($errors, 'address') ?>">
        <label for="address">Delivery address <span class="text-muted">(optional)</span></label>
        <input type="text" id="address" name="address"
               value="<?= e($values['address']) ?>"
               autocomplete="street-address">
        <span class="field__hint">Saved for faster checkout. You can change it on any order.</span>
      </div>

      <div class="<?= field_class($errors, 'password') ?>">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               autocomplete="new-password" required>
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

      <button class="btn btn--accent btn--full" type="submit">Create account</button>
    </form>

    <p style="margin-top:1.25rem">
      Already registered? <a href="<?= url('login.php') ?>">Sign in</a>.
    </p>

    <p class="text-muted" style="font-size:.9rem">
      Run a restaurant?
      <a href="<?= url('restaurant_register.php') ?>">List it on Smart Eats</a> instead.
    </p>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
