<?php
/**
 * Smart Eats - sign in
 *
 * Authenticates customers, restaurant owners, restaurant staff and
 * platform administrators through the same form, then routes each role
 * to the correct landing page. One form rather than four means there is
 * one place where the lockout, the rehash and the generic failure
 * message live.
 */

require_once __DIR__ . '/includes/auth.php';

// Already signed in, nothing to do here.
if (is_logged_in() && current_user()) {
    redirect(home_for_role(current_role()));
}

$page_title = 'Sign in';
$errors     = [];
$email      = '';

if (is_post()) {
    verify_csrf();

    $email    = strtolower(input('email'));
    $password = $_POST['password'] ?? '';

    if ($email === '') {
        $errors['email'] = 'Enter your email address.';
    } elseif (!is_valid_email($email)) {
        $errors['email'] = 'That does not look like a valid email address.';
    }

    if ($password === '') {
        $errors['password'] = 'Enter your password.';
    }

    if (!$errors && is_locked_out($email)) {
        $errors['form'] = 'Too many failed attempts. Wait '
            . LOGIN_LOCKOUT_MINUTES . ' minutes and try again.';
    }

    if (!$errors) {
        $user = db_one(
            'SELECT id, full_name, email, password_hash, role, restaurant_id, is_active
             FROM users WHERE email = ? LIMIT 1',
            [$email]
        );

        // One generic message for both a wrong address and a wrong
        // password, so the form cannot be used to discover which email
        // addresses hold accounts.
        if (!$user || !password_verify($password, $user['password_hash'])) {
            record_login_attempt($email, false);
            $remaining = LOGIN_MAX_ATTEMPTS - recent_failed_attempts($email);
            $errors['form'] = 'Email address or password is incorrect.'
                . ($remaining > 0 && $remaining <= 2
                    ? ' ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' left.'
                    : '');
        } elseif ((int) $user['is_active'] !== 1) {
            record_login_attempt($email, false);
            $errors['form'] = 'This account has been deactivated. Contact Smart Eats support.';
        } else {
            // Upgrade the stored hash if PHP's default cost has changed.
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                db_run(
                    'UPDATE users SET password_hash = ? WHERE id = ?',
                    [password_hash($password, PASSWORD_DEFAULT), $user['id']]
                );
            }

            record_login_attempt($email, true);
            login_user($user);

            $target = $_SESSION['redirect_after_login'] ?? '';
            unset($_SESSION['redirect_after_login']);

            flash('Signed in as ' . $user['full_name'] . '.', 'success');
            redirect($target !== '' ? $target : home_for_role($user['role']));
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap" style="max-width:460px">

    <h1>Sign in</h1>
    <p class="text-muted">
      For customers, restaurant owners and kitchen staff. You can also
      <a href="<?= url('restaurants.php') ?>">order as a guest</a>.
    </p>

    <?php if (isset($errors['form'])): ?>
      <div class="alert alert--error" role="alert"><?= e($errors['form']) ?></div>
    <?php endif; ?>

    <form class="card" method="post" action="<?= url('login.php') ?>" data-once novalidate>
      <?= csrf_field() ?>

      <div class="<?= field_class($errors, 'email') ?>">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>"
               autocomplete="username" required autofocus>
        <?= field_error($errors, 'email') ?>
      </div>

      <div class="<?= field_class($errors, 'password') ?>">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               autocomplete="current-password" required>
        <?= field_error($errors, 'password') ?>
      </div>

      <button class="btn btn--accent btn--full" type="submit">Sign in</button>
    </form>

    <p style="margin-top:1.25rem">
      No account yet? <a href="<?= url('register.php') ?>">Create one</a>.
    </p>

    <p class="text-muted" style="font-size:.9rem">
      Run a restaurant?
      <a href="<?= url('restaurant_register.php') ?>">List it on Smart Eats</a>.
    </p>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
