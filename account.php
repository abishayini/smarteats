<?php
/**
 * Smart Eats - my account
 *
 * Lets a signed-in user update their contact details and change their
 * password. Changing a password requires the current one, so an
 * unattended open session cannot be used to take over the account.
 */

require_once __DIR__ . '/includes/auth.php';
require_login();

$page_title = 'My account';
$user       = current_user();

$errors = [];
$values = [
    'full_name' => $user['full_name'],
    'email'     => $user['email'],
    'phone'     => $user['phone'] ?? '',
    'address'   => $user['address'] ?? '',
];

if (is_post()) {
    verify_csrf();
    $action = input('action');

    /* ---------------- Update contact details ---------------- */
    if ($action === 'details') {
        $values['full_name'] = input('full_name');
        $values['email']     = strtolower(input('email'));
        $values['phone']     = input('phone');
        $values['address']   = input('address');

        if ($values['full_name'] === '') {
            $errors['full_name'] = 'Enter your name.';
        }

        if ($values['email'] === '') {
            $errors['email'] = 'Enter your email address.';
        } elseif (!is_valid_email($values['email'])) {
            $errors['email'] = 'That does not look like a valid email address.';
        } elseif (db_value(
            'SELECT 1 FROM users WHERE email = ? AND id <> ?',
            [$values['email'], $user['id']]
        )) {
            $errors['email'] = 'Another account already uses this address.';
        }

        if ($values['phone'] !== '' && !is_valid_phone($values['phone'])) {
            $errors['phone'] = 'Enter a valid phone number.';
        }

        if (!$errors) {
            db_run(
                'UPDATE users SET full_name = ?, email = ?, phone = ?, address = ?
                 WHERE id = ?',
                [
                    $values['full_name'],
                    $values['email'],
                    $values['phone'] !== '' ? $values['phone'] : null,
                    $values['address'] !== '' ? $values['address'] : null,
                    $user['id'],
                ]
            );
            $_SESSION['user_name'] = $values['full_name'];
            flash('Your details have been updated.', 'success');
            redirect('account.php');
        }
    }

    /* ---------------- Change password ---------------- */
    if ($action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['new_password_confirm'] ?? '';

        $stored = db_value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);

        if (!password_verify($current, (string) $stored)) {
            $errors['current_password'] = 'That is not your current password.';
        }

        if ($problem = password_problem($new)) {
            $errors['new_password'] = $problem;
        } elseif ($new === $current) {
            $errors['new_password'] = 'Choose a password you have not used here before.';
        }

        if ($new !== $confirm) {
            $errors['new_password_confirm'] = 'The two passwords do not match.';
        }

        if (!$errors) {
            db_run(
                'UPDATE users SET password_hash = ? WHERE id = ?',
                [password_hash($new, PASSWORD_DEFAULT), $user['id']]
            );
            session_regenerate_id(true);
            flash('Your password has been changed.', 'success');
            redirect('account.php');
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap" style="max-width:640px">

    <h1>My account</h1>
    <p class="text-muted">
      Signed in as <?= e($user['email']) ?> &middot; <?= e(ucfirst($user['role'])) ?>
    </p>

    <form class="card" method="post" data-once novalidate style="margin-bottom:1.5rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="details">

      <h2 class="card__title">Your details</h2>

      <div class="<?= field_class($errors, 'full_name') ?>">
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name"
               value="<?= e($values['full_name']) ?>" autocomplete="name" required>
        <?= field_error($errors, 'full_name') ?>
      </div>

      <div class="<?= field_class($errors, 'email') ?>">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email"
               value="<?= e($values['email']) ?>" autocomplete="email" required>
        <?= field_error($errors, 'email') ?>
      </div>

      <div class="<?= field_class($errors, 'phone') ?>">
        <label for="phone">Phone number</label>
        <input type="tel" id="phone" name="phone"
               value="<?= e($values['phone']) ?>" autocomplete="tel">
        <?= field_error($errors, 'phone') ?>
      </div>

      <div class="field">
        <label for="address">Delivery address</label>
        <input type="text" id="address" name="address"
               value="<?= e($values['address']) ?>" autocomplete="street-address">
      </div>

      <button class="btn" type="submit">Save changes</button>
    </form>

    <form class="card" method="post" data-once novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">

      <h2 class="card__title">Change password</h2>

      <div class="<?= field_class($errors, 'current_password') ?>">
        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password"
               autocomplete="current-password" required>
        <?= field_error($errors, 'current_password') ?>
      </div>

      <div class="<?= field_class($errors, 'new_password') ?>">
        <label for="new_password">New password</label>
        <input type="password" id="new_password" name="new_password"
               autocomplete="new-password" required>
        <span class="field__hint">
          At least <?= PASSWORD_MIN_LENGTH ?> characters, including a letter and a number.
        </span>
        <?= field_error($errors, 'new_password') ?>
      </div>

      <div class="<?= field_class($errors, 'new_password_confirm') ?>">
        <label for="new_password_confirm">Confirm new password</label>
        <input type="password" id="new_password_confirm" name="new_password_confirm"
               autocomplete="new-password" required>
        <?= field_error($errors, 'new_password_confirm') ?>
      </div>

      <button class="btn" type="submit">Change password</button>
    </form>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
