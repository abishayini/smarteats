<?php
/**
 * Smart Eats - user accounts
 *
 * A restaurant owner manages the kitchen staff of their own restaurant:
 * creating an account, resetting a password, clearing a lockout and
 * disabling someone who has left. A platform administrator sees the same
 * screen for whichever restaurant is selected, plus the platform's own
 * administrators and the customer list.
 *
 * Two rules keep this safe on a shared platform. A vendor may only ever
 * create the 'staff' role, so this screen cannot be used to mint an
 * administrator; and every account acted on is re-read from the database
 * and checked against the current restaurant, so an id posted from
 * another restaurant's page is refused.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restaurants.php';

require_role('admin', 'vendor');
require_active_restaurant();

// The sidebar switcher posts back to this page, so it is dealt with
// before this screen's own POST handling sees an action it cannot place.
handle_restaurant_switch();

$me         = current_user();
$isAdmin    = has_role('admin');
$restaurant = require_panel_restaurant();
$restaurantId = (int) $restaurant['id'];

$page_title = 'Staff';
$errors     = [];
$values     = ['full_name' => '', 'email' => '', 'phone' => '', 'role' => 'staff'];

/**
 * Load an account this screen is allowed to act on.
 * Returns null when the id belongs to somebody else's restaurant.
 */
function manageable_user(int $id, bool $isAdmin, int $restaurantId): ?array
{
    $target = db_one('SELECT * FROM users WHERE id = ?', [$id]);

    if (!$target) {
        return null;
    }

    // A vendor may only touch their own restaurant's staff.
    if (!$isAdmin) {
        if ($target['role'] !== 'staff'
            || (int) $target['restaurant_id'] !== $restaurantId) {
            return null;
        }
    }

    return $target;
}

if (is_post()) {
    verify_csrf();

    $action = input('action');
    $userId = (int) input('id');

    /* ---------------- Create a staff account ------------------------ */
    if ($action === 'create') {
        $values['full_name'] = input('full_name');
        $values['email']     = strtolower(input('email'));
        $values['phone']     = input('phone');
        $values['role']      = input('role');
        $password            = $_POST['password'] ?? '';

        if ($values['full_name'] === '') {
            $errors['full_name'] = 'Enter a name.';
        }

        if ($values['email'] === '') {
            $errors['email'] = 'Enter an email address.';
        } elseif (!is_valid_email($values['email'])) {
            $errors['email'] = 'That does not look like a valid email address.';
        } elseif (db_value('SELECT 1 FROM users WHERE email = ?', [$values['email']])) {
            $errors['email'] = 'An account already uses that address.';
        }

        // A vendor gets exactly one option; the value is forced rather
        // than trusted, so editing the select in the browser achieves
        // nothing.
        $allowedRoles = $isAdmin ? ['staff', 'vendor', 'admin'] : ['staff'];

        if (!in_array($values['role'], $allowedRoles, true)) {
            if (!$isAdmin) {
                $values['role'] = 'staff';
            } else {
                $errors['role'] = 'Choose a role.';
            }
        }

        if ($problem = password_problem($password)) {
            $errors['password'] = $problem;
        }

        if (!$errors) {
            // Platform administrators are not tied to a restaurant;
            // everyone else on this screen is.
            $linkedRestaurant = $values['role'] === 'admin' ? null : $restaurantId;

            db_insert(
                'INSERT INTO users (full_name, email, password_hash, phone, role, restaurant_id, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 1)',
                [
                    $values['full_name'],
                    $values['email'],
                    password_hash($password, PASSWORD_DEFAULT),
                    $values['phone'] !== '' ? $values['phone'] : null,
                    $values['role'],
                    $linkedRestaurant,
                ]
            );
            flash($values['full_name'] . ' can now sign in.', 'success');
            redirect('admin/users.php');
        }
    }

    /* ---------------- Reset a password ------------------------------ */
    if ($action === 'reset') {
        $target   = manageable_user($userId, $isAdmin, $restaurantId);
        $password = $_POST['new_password'] ?? '';

        if (!$target) {
            flash('That account could not be found.', 'error');
        } elseif ($problem = password_problem($password)) {
            flash('Password not changed: ' . lcfirst($problem), 'error');
        } else {
            db_run(
                'UPDATE users SET password_hash = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $userId]
            );
            db_run('DELETE FROM login_attempts WHERE email = ?', [$target['email']]);
            flash('Password reset for ' . $target['email'] . '.', 'success');
        }

        redirect('admin/users.php');
    }

    /* ---------------- Clear a lockout ------------------------------- */
    if ($action === 'unlock') {
        $target = manageable_user($userId, $isAdmin, $restaurantId);

        if ($target) {
            db_run('DELETE FROM login_attempts WHERE email = ?', [$target['email']]);
            flash($target['full_name'] . ' can try signing in again.', 'success');
        } else {
            flash('That account could not be found.', 'error');
        }

        redirect('admin/users.php');
    }

    /* ---------------- Activate or deactivate ------------------------ */
    if ($action === 'toggle') {
        $target = manageable_user($userId, $isAdmin, $restaurantId);

        if (!$target) {
            flash('That account could not be found.', 'error');
        } elseif ((int) $target['id'] === (int) $me['id']) {
            flash('You cannot deactivate your own account.', 'error');
        } else {
            $now = (int) $target['is_active'] === 1 ? 0 : 1;
            db_run('UPDATE users SET is_active = ? WHERE id = ?', [$now, $userId]);
            flash(
                $target['full_name'] . ($now ? ' has been reactivated.' : ' can no longer sign in.'),
                'success'
            );
        }

        redirect('admin/users.php');
    }
}

// The lockout window is inlined rather than bound. It is an integer
// constant defined in auth.php and never user input, and keeping it out
// of the parameter list avoids confusing the binding order of a query
// that already carries a correlated subquery.
$lockoutWindow = (int) LOGIN_LOCKOUT_MINUTES;

// The restaurant's own team: its owner and its kitchen staff.
$team = db_all(
    "SELECT u.id, u.full_name, u.email, u.phone, u.role, u.is_active, u.created_at,
            (SELECT COUNT(*) FROM login_attempts la
              WHERE la.email = u.email AND la.was_successful = 0
                AND la.attempted_at > DATE_SUB(NOW(), INTERVAL $lockoutWindow MINUTE)) AS failed_attempts
     FROM users u
     WHERE u.restaurant_id = ? AND u.role IN ('staff','vendor')
     ORDER BY u.role DESC, u.full_name",
    [$restaurantId]
);

// Platform administrators, visible to administrators only.
$platformAdmins = $isAdmin
    ? db_all(
        "SELECT u.id, u.full_name, u.email, u.role, u.is_active,
                (SELECT COUNT(*) FROM login_attempts la
                  WHERE la.email = u.email AND la.was_successful = 0
                    AND la.attempted_at > DATE_SUB(NOW(), INTERVAL $lockoutWindow MINUTE)) AS failed_attempts
         FROM users u
         WHERE u.role = 'admin'
         ORDER BY u.full_name"
      )
    : [];

// Customers of this restaurant, which is as much as a vendor needs.
$customers = db_all(
    "SELECT u.id, u.full_name, u.email, u.phone, u.is_active,
            COUNT(o.id) AS order_count, MAX(o.created_at) AS last_order
     FROM users u
     JOIN orders o ON o.user_id = u.id AND o.restaurant_id = ?
     WHERE u.role = 'customer'
     GROUP BY u.id, u.full_name, u.email, u.phone, u.is_active
     ORDER BY last_order DESC
     LIMIT 20",
    [$restaurantId]
);

include __DIR__ . '/../includes/panel_header.php';
?>

<p class="text-muted" style="margin-bottom:1.25rem">
  The team at <strong><?= e($restaurant['name']) ?></strong>.
  Staff created here can open the live order board for this restaurant only.
</p>

<div class="grid grid--2" style="align-items:start">

  <div>
    <h2>Owner and kitchen staff</h2>

    <?php if (!$team): ?>
      <div class="empty"><p>No staff accounts yet.</p></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="data">
          <thead>
            <tr><th>Name</th><th>Role</th><th>Status</th><th class="text-right">Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($team as $person): ?>
              <tr>
                <td>
                  <strong><?= e($person['full_name']) ?></strong>
                  <?php if ((int) $person['id'] === (int) $me['id']): ?>
                    <span class="badge">You</span>
                  <?php endif; ?>
                  <br><span class="text-muted" style="font-size:.82rem"><?= e($person['email']) ?></span>
                </td>
                <td><?= $person['role'] === 'vendor' ? 'Owner' : 'Kitchen staff' ?></td>
                <td>
                  <span class="badge badge--<?= $person['is_active'] ? 'completed' : 'cancelled' ?>">
                    <?= $person['is_active'] ? 'Active' : 'Disabled' ?>
                  </span>
                  <?php if ((int) $person['failed_attempts'] >= LOGIN_MAX_ATTEMPTS): ?>
                    <br><span class="badge badge--cancelled">Locked out</span>
                  <?php elseif ((int) $person['failed_attempts'] > 0): ?>
                    <br><span class="badge badge--pending">
                      <?= (int) $person['failed_attempts'] ?> failed
                    </span>
                  <?php endif; ?>
                </td>
                <td class="text-right">
                  <?php $editable = $isAdmin || $person['role'] === 'staff'; ?>
                  <div class="row" style="justify-content:flex-end;gap:.35rem">

                    <?php if ($editable): ?>
                      <form method="post" class="row" style="gap:.3rem">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reset">
                        <input type="hidden" name="id" value="<?= (int) $person['id'] ?>">
                        <label class="sr-only" for="pw-<?= (int) $person['id'] ?>">New password</label>
                        <input type="text" id="pw-<?= (int) $person['id'] ?>" name="new_password"
                               placeholder="New password" style="width:150px" autocomplete="off">
                        <button class="btn btn--sm btn--ghost" type="submit">Reset</button>
                      </form>

                      <?php if ((int) $person['failed_attempts'] > 0): ?>
                        <form method="post">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="unlock">
                          <input type="hidden" name="id" value="<?= (int) $person['id'] ?>">
                          <button class="btn btn--sm btn--ghost" type="submit">Unlock</button>
                        </form>
                      <?php endif; ?>

                      <?php if ((int) $person['id'] !== (int) $me['id']): ?>
                        <form method="post">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="toggle">
                          <input type="hidden" name="id" value="<?= (int) $person['id'] ?>">
                          <button class="btn btn--sm btn--ghost" type="submit"
                                  <?= $person['is_active']
                                      ? 'data-confirm="Stop ' . e($person['full_name']) . ' signing in?"'
                                      : '' ?>>
                            <?= $person['is_active'] ? 'Disable' : 'Enable' ?>
                          </button>
                        </form>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="text-muted" style="font-size:.82rem">Owner account</span>
                    <?php endif; ?>

                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <p class="text-muted" style="font-size:.86rem;margin-top:.85rem">
      A password typed here is hashed by PHP before it is stored, so nothing can
      be altered in transit. Resetting a password also clears any lockout.
      An account showing <strong>Locked out</strong> is refusing even the correct
      password until the lockout expires or is cleared here.
    </p>
  </div>

  <div class="card">
    <h2 class="card__title">Add a staff account</h2>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <div class="<?= field_class($errors, 'full_name') ?>">
        <label for="full_name">Name</label>
        <input type="text" id="full_name" name="full_name" value="<?= e($values['full_name']) ?>" required>
        <?= field_error($errors, 'full_name') ?>
      </div>

      <div class="<?= field_class($errors, 'email') ?>">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e($values['email']) ?>" required>
        <?= field_error($errors, 'email') ?>
      </div>

      <div class="field">
        <label for="phone">Phone <span class="text-muted">(optional)</span></label>
        <input type="tel" id="phone" name="phone" value="<?= e($values['phone']) ?>">
      </div>

      <div class="<?= field_class($errors, 'role') ?>">
        <label for="role">Role</label>
        <?php if ($isAdmin): ?>
          <select id="role" name="role">
            <option value="staff"  <?= $values['role'] === 'staff'  ? 'selected' : '' ?>>
              Kitchen staff, order board only
            </option>
            <option value="vendor" <?= $values['role'] === 'vendor' ? 'selected' : '' ?>>
              Restaurant owner, manages this restaurant
            </option>
            <option value="admin"  <?= $values['role'] === 'admin'  ? 'selected' : '' ?>>
              Platform administrator, full access
            </option>
          </select>
          <span class="field__hint">
            Staff and owners are attached to <?= e($restaurant['name']) ?>.
            Administrators belong to the platform.
          </span>
        <?php else: ?>
          <input type="hidden" name="role" value="staff">
          <input type="text" id="role" value="Kitchen staff, order board only" disabled>
          <span class="field__hint">
            Staff can open your live order board and move orders on. They cannot
            change your menu, prices or settings.
          </span>
        <?php endif; ?>
        <?= field_error($errors, 'role') ?>
      </div>

      <div class="<?= field_class($errors, 'password') ?>">
        <label for="password">Password</label>
        <input type="text" id="password" name="password" autocomplete="off" required>
        <span class="field__hint">
          At least <?= PASSWORD_MIN_LENGTH ?> characters with a letter and a number.
          Shown as you type so it can be passed on accurately.
        </span>
        <?= field_error($errors, 'password') ?>
      </div>

      <button class="btn btn--accent btn--full" type="submit">Create account</button>
    </form>
  </div>

</div>

<?php if ($isAdmin && $platformAdmins): ?>
  <h2 style="margin-top:2.5rem">Platform administrators</h2>
  <div class="table-scroll">
    <table class="data">
      <thead>
        <tr><th>Name</th><th>Status</th><th class="text-right">Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($platformAdmins as $person): ?>
          <tr>
            <td>
              <strong><?= e($person['full_name']) ?></strong>
              <?php if ((int) $person['id'] === (int) $me['id']): ?>
                <span class="badge">You</span>
              <?php endif; ?>
              <br><span class="text-muted" style="font-size:.82rem"><?= e($person['email']) ?></span>
            </td>
            <td>
              <span class="badge badge--<?= $person['is_active'] ? 'completed' : 'cancelled' ?>">
                <?= $person['is_active'] ? 'Active' : 'Disabled' ?>
              </span>
              <?php if ((int) $person['failed_attempts'] >= LOGIN_MAX_ATTEMPTS): ?>
                <span class="badge badge--cancelled">Locked out</span>
              <?php endif; ?>
            </td>
            <td class="text-right">
              <?php if ((int) $person['id'] !== (int) $me['id']): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int) $person['id'] ?>">
                  <button class="btn btn--sm btn--ghost" type="submit"
                          data-confirm="Stop <?= e($person['full_name']) ?> signing in?">
                    <?= $person['is_active'] ? 'Disable' : 'Enable' ?>
                  </button>
                </form>
              <?php else: ?>
                <span class="text-muted" style="font-size:.82rem">Your account</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<h2 style="margin-top:2.5rem">Customers who have ordered from you</h2>

<?php if (!$customers): ?>
  <div class="empty"><p>No registered customer has ordered from <?= e($restaurant['name']) ?> yet.</p></div>
<?php else: ?>
  <div class="table-scroll">
    <table class="data">
      <thead>
        <tr><th>Name</th><th>Contact</th><th>Orders</th><th>Last order</th></tr>
      </thead>
      <tbody>
        <?php foreach ($customers as $customer): ?>
          <tr>
            <td><?= e($customer['full_name']) ?></td>
            <td>
              <?= e($customer['email']) ?>
              <?php if ($customer['phone']): ?>
                <br><span class="text-muted" style="font-size:.82rem"><?= e($customer['phone']) ?></span>
              <?php endif; ?>
            </td>
            <td><?= (int) $customer['order_count'] ?></td>
            <td><?= e(pretty_date($customer['last_order'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="text-muted" style="font-size:.86rem">
    Showing the 20 most recent. You see a customer's details because they
    ordered from you; customers of other restaurants are not listed here.
  </p>
<?php endif; ?>

<?php include __DIR__ . '/../includes/panel_footer.php'; ?>
