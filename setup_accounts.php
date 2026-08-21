<?php
/**
 * Smart Eats - account repair tool
 *
 * Run this once from the browser if a seeded password is refused:
 *   http://localhost/smarteats/setup_accounts.php
 *
 * WHY THIS EXISTS
 * A bcrypt hash is a 60 character string containing $ / . characters. It
 * survives being copied through a SQL file most of the time, and when it
 * does not the symptom is confusing: the account exists, the password is
 * right, and the sign-in is still refused. This script sidesteps the
 * problem by generating every hash with password_hash() on this machine
 * and verifying it with password_verify() before reporting success, so
 * nothing is ever copied anywhere.
 *
 * It also repairs the Phase 11 links: each staff and owner account is
 * attached to the correct restaurant, and each restaurant is attached to
 * its owner. An account with the right password but no restaurant would
 * sign in to an empty panel.
 *
 * DELETE THIS FILE once an administrator can sign in. Anyone who can
 * reach it can reset these passwords.
 */

require_once __DIR__ . '/includes/auth.php';

$results = [];
$ran     = false;

/**
 * The accounts this tool guarantees.
 * restaurant is a slug, or null for a platform administrator.
 */
$accounts = [
    // Platform administrators
    ['Platform Manager',      'manager@smarteats.test',        'Manager@2026', 'admin',  null],
    ['System Administrator',  'admin@smarteats.test',          'admin123',     'admin',  null],

    // Restaurant owners
    ['Nadia Karim',           'owner.kitchen@smarteats.test',  'Vendor@2026',  'vendor', 'smart-eats-kitchen'],
    ['Ravi Sharma',           'owner.spice@smarteats.test',    'Vendor@2026',  'vendor', 'spice-route'],
    ['Giulia Rossi',          'owner.bella@smarteats.test',    'Vendor@2026',  'vendor', 'bella-napoli'],
    ['Tom Whitfield',         'owner.green@smarteats.test',    'Vendor@2026',  'vendor', 'green-bowl'],

    // Kitchen staff
    ['Kitchen Team',          'kitchen@smarteats.test',        'Kitchen@2026', 'staff',  'smart-eats-kitchen'],
    ['Kitchen Staff',         'staff@smarteats.test',          'staff123',     'staff',  'smart-eats-kitchen'],
    ['Spice Route Kitchen',   'kitchen.spice@smarteats.test',  'Kitchen@2026', 'staff',  'spice-route'],
    ['Bella Napoli Kitchen',  'kitchen.bella@smarteats.test',  'Kitchen@2026', 'staff',  'bella-napoli'],
];

if (is_post()) {
    $ran = true;

    foreach ($accounts as [$name, $email, $password, $role, $slug]) {

        // The hash is produced here, on this machine, by PHP.
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $restaurantId = $slug
            ? db_value('SELECT id FROM restaurants WHERE slug = ?', [$slug])
            : null;

        $existing = db_one('SELECT id FROM users WHERE email = ?', [$email]);

        if ($existing) {
            db_run(
                'UPDATE users
                 SET full_name = ?, password_hash = ?, role = ?, restaurant_id = ?, is_active = 1
                 WHERE id = ?',
                [$name, $hash, $role, $restaurantId, $existing['id']]
            );
            $userId = (int) $existing['id'];
            $what   = 'updated';
        } else {
            $userId = db_insert(
                'INSERT INTO users (full_name, email, password_hash, role, restaurant_id, is_active)
                 VALUES (?, ?, ?, ?, ?, 1)',
                [$name, $email, $hash, $role, $restaurantId]
            );
            $what = 'created';
        }

        // Any lockout from the failed attempts is cleared at the same time.
        db_run('DELETE FROM login_attempts WHERE email = ?', [$email]);

        // Reattach the restaurant to its owner.
        if ($role === 'vendor' && $restaurantId) {
            db_run(
                'UPDATE restaurants SET owner_user_id = ? WHERE id = ?',
                [$userId, $restaurantId]
            );
        }

        // Read the hash back and verify it, so this page never reports
        // success for a credential that will not actually work.
        $stored   = (string) db_value('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        $verified = password_verify($password, $stored);

        $results[] = [
            'email'      => $email,
            'password'   => $password,
            'role'       => $role,
            'restaurant' => $slug ?: 'Platform',
            'linked'     => $slug ? (bool) $restaurantId : true,
            'action'     => $what,
            'verified'   => $verified,
        ];
    }
}

$restaurantCount = (int) db_value('SELECT COUNT(*) FROM restaurants');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Account setup | Smart Eats</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= ASSETS_URL ?>/css/style.css">
</head>
<body>

<main id="main">
<section class="section">
  <div class="wrap" style="max-width:820px">

    <h1>Account setup and repair</h1>
    <p class="text-muted">
      Regenerates every seeded password on this machine and reattaches each
      account to its restaurant. Use it if a sign-in is refused even though the
      password looks correct.
    </p>

    <?php if ($restaurantCount === 0): ?>
      <div class="alert alert--error" role="alert">
        <strong>No restaurants found.</strong>
        Import <code>sql/smarteats.sql</code> first, otherwise the staff and
        owner accounts have nothing to attach to.
      </div>
    <?php endif; ?>

    <?php if (!$ran): ?>

      <div class="card">
        <h2 class="card__title">What this will do</h2>
        <ul class="stack" style="padding-left:1.1rem">
          <li>Create or update <?= count($accounts) ?> accounts.</li>
          <li>Hash each password with PHP on this machine and verify it.</li>
          <li>Attach every owner and staff account to the right restaurant.</li>
          <li>Clear any lockout on those addresses.</li>
        </ul>

        <form method="post">
          <button class="btn btn--accent btn--lg" type="submit">Repair the accounts</button>
        </form>
      </div>

    <?php else: ?>

      <div class="card">
        <h2 class="card__title">Result</h2>
        <div class="table-scroll">
          <table class="data">
            <thead>
              <tr><th>Email</th><th>Password</th><th>Role</th><th>Restaurant</th><th>Verified</th></tr>
            </thead>
            <tbody>
              <?php foreach ($results as $row): ?>
                <tr>
                  <td><?= e($row['email']) ?></td>
                  <td><code><?= e($row['password']) ?></code></td>
                  <td><?= e($row['role']) ?></td>
                  <td>
                    <?= e($row['restaurant']) ?>
                    <?php if (!$row['linked']): ?>
                      <br><span class="badge badge--cancelled">Not found</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge badge--<?= $row['verified'] ? 'completed' : 'cancelled' ?>">
                      <?= $row['verified'] ? 'Yes' : 'FAILED' ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <p style="margin-top:1.25rem">
          <a class="btn btn--accent" href="<?= url('login.php') ?>">Go to sign in</a>
        </p>
      </div>

      <div class="alert alert--warning" role="alert" style="margin-top:1.5rem">
        <strong>Delete this file now.</strong>
        Remove <code>setup_accounts.php</code> from the project folder. Anyone who
        can reach it can reset these passwords. From here on, accounts are
        managed properly from the panel's Staff screen, which also hashes
        locally.
      </div>

    <?php endif; ?>

  </div>
</section>
</main>

</body>
</html>
