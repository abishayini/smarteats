<?php
/**
 * Smart Eats - attach existing images
 *
 * Uploading a photo through the panel one dish at a time is the right
 * way to add a new picture. It is the wrong way to reconnect a folder of
 * images that is already on disk, which is the situation after a
 * database rebuild: the files survived, the rows that pointed at them
 * did not.
 *
 * This tool scans uploads/menu and uploads/logos, lists what it finds,
 * and lets every dish and every restaurant be pointed at a file in one
 * pass. It can also guess: a file called chicken-wings.jpg is offered to
 * the dish called Chicken wings, because both reduce to the same slug.
 *
 * Nothing is moved, renamed or deleted. Only the `image` column on
 * menu_items and the `logo` column on restaurants are written.
 *
 * Delete this file once the images are attached.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/restaurants.php';

require_role('admin', 'vendor');

$page_title = 'Attach images';

/** Image files sitting in one of the uploads folders. */
function files_in(string $folder): array
{
    $path = UPLOADS_PATH . '/' . $folder;

    if (!is_dir($path)) {
        return [];
    }

    $files = [];
    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
            continue;
        }
        if (!is_file($path . '/' . $entry)) {
            continue;
        }
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $files[] = $entry;
        }
    }

    natcasesort($files);
    return array_values($files);
}

/**
 * Reduce a name or filename to a comparison key.
 * "Chicken wings" and "chicken-wings.jpg" both become "chickenwings",
 * which is what makes the automatic match work across the two.
 */
function match_key(string $value): string
{
    $value = pathinfo($value, PATHINFO_FILENAME);
    return preg_replace('/[^a-z0-9]/', '', strtolower($value));
}

/** Best filename for a name, or '' when nothing is close enough. */
function guess_file(string $name, array $files): string
{
    $wanted = match_key($name);

    if ($wanted === '') {
        return '';
    }

    // An exact match first.
    foreach ($files as $file) {
        if (match_key($file) === $wanted) {
            return $file;
        }
    }

    // Then a file whose name is contained in the dish name or the other
    // way round, longest first so "chickenfriedrice" beats "rice".
    $best = '';
    $bestLength = 0;

    foreach ($files as $file) {
        $key = match_key($file);
        if ($key === '' || strlen($key) < 4) {
            continue;
        }
        if ((str_contains($wanted, $key) || str_contains($key, $wanted))
            && strlen($key) > $bestLength) {
            $best = $file;
            $bestLength = strlen($key);
        }
    }

    return $best;
}

$menuFiles = files_in('menu');
$logoFiles = files_in('logos');

$scope   = panel_restaurant_id();       // null for an admin viewing all
$isAdmin = has_role('admin');

/* ------------------------------------------------------------------ */
/* Saving                                                              */
/* ------------------------------------------------------------------ */

$applied = [];

if (is_post()) {
    verify_csrf();

    $action = input('action');

    if ($action === 'save_dishes') {
        $chosen  = $_POST['dish'] ?? [];
        $changed = 0;

        foreach ($chosen as $dishId => $file) {
            $dishId = (int) $dishId;
            $file   = trim((string) $file);

            $dish = db_one(
                'SELECT id, restaurant_id, image FROM menu_items WHERE id = ?',
                [$dishId]
            );

            if (!$dish) {
                continue;
            }

            // A vendor may only touch their own restaurant's dishes,
            // whatever ids are posted.
            if (!can_manage_restaurant((int) $dish['restaurant_id'])) {
                continue;
            }

            // Only a filename this tool actually listed is accepted, so
            // a path cannot be typed into the form.
            if ($file !== '' && !in_array($file, $menuFiles, true)) {
                continue;
            }

            if ((string) $dish['image'] === $file) {
                continue;
            }

            db_run(
                'UPDATE menu_items SET image = ? WHERE id = ?',
                [$file !== '' ? $file : null, $dishId]
            );
            $changed++;
        }

        flash(
            $changed
                ? $changed . ' dish' . ($changed === 1 ? '' : 'es') . ' updated.'
                : 'No changes to save.',
            $changed ? 'success' : 'info'
        );
        redirect('attach_images.php');
    }

    if ($action === 'save_logos') {
        $chosen  = $_POST['logo'] ?? [];
        $changed = 0;

        foreach ($chosen as $restaurantId => $file) {
            $restaurantId = (int) $restaurantId;
            $file         = trim((string) $file);

            if (!can_manage_restaurant($restaurantId)) {
                continue;
            }

            if ($file !== '' && !in_array($file, $logoFiles, true)) {
                continue;
            }

            $current = (string) db_value(
                'SELECT logo FROM restaurants WHERE id = ?',
                [$restaurantId]
            );

            if ($current === $file) {
                continue;
            }

            db_run(
                'UPDATE restaurants SET logo = ? WHERE id = ?',
                [$file !== '' ? $file : null, $restaurantId]
            );
            $changed++;
        }

        flash(
            $changed
                ? $changed . ' logo' . ($changed === 1 ? '' : 's') . ' updated.'
                : 'No changes to save.',
            $changed ? 'success' : 'info'
        );
        redirect('attach_images.php');
    }
}

$guessing = input('guess') === '1';

/* ------------------------------------------------------------------ */
/* Loading                                                             */
/* ------------------------------------------------------------------ */

$sql = 'SELECT m.id, m.name, m.image, m.restaurant_id,
               r.name AS restaurant_name, c.name AS category_name
        FROM menu_items m
        JOIN restaurants r ON r.id = m.restaurant_id
        JOIN categories c  ON c.id = m.category_id
        WHERE m.is_active = 1';
$params = [];

if ($scope) {
    $sql .= ' AND m.restaurant_id = ?';
    $params[] = $scope;
}

$sql .= ' ORDER BY r.name, c.sort_order, m.name';
$dishes = db_all($sql, $params);

$restaurantSql = 'SELECT id, name, logo FROM restaurants';
$restaurantParams = [];

if ($scope) {
    $restaurantSql .= ' WHERE id = ?';
    $restaurantParams[] = $scope;
}

$restaurants = db_all($restaurantSql . ' ORDER BY name', $restaurantParams);

// Group dishes by restaurant for display.
$grouped = [];
foreach ($dishes as $dish) {
    $grouped[$dish['restaurant_name']][] = $dish;
}

$attachedCount = 0;
foreach ($dishes as $dish) {
    if ($dish['image']) {
        $attachedCount++;
    }
}

// Files not used by any dish, so a forgotten photo is visible.
$usedMenu = array_filter(array_column($dishes, 'image'));
$unusedMenu = array_values(array_diff($menuFiles, $usedMenu));

include __DIR__ . '/includes/panel_header.php';
?>

<p class="text-muted" style="margin-bottom:1.5rem">
  Files already sitting in your uploads folders, ready to be pointed at.
  Nothing here is moved, renamed or deleted.
</p>

<div class="stats">
  <div class="stat stat--accent">
    <p class="stat__label">Files in uploads/menu</p>
    <p class="stat__value"><?= count($menuFiles) ?></p>
  </div>
  <div class="stat stat--ok">
    <p class="stat__label">Dishes with a photo</p>
    <p class="stat__value"><?= $attachedCount ?> / <?= count($dishes) ?></p>
  </div>
  <div class="stat">
    <p class="stat__label">Files in uploads/logos</p>
    <p class="stat__value"><?= count($logoFiles) ?></p>
  </div>
  <div class="stat<?= $unusedMenu ? ' stat--warn' : '' ?>">
    <p class="stat__label">Menu files not used</p>
    <p class="stat__value"><?= count($unusedMenu) ?></p>
  </div>
</div>

<?php if (!$menuFiles && !$logoFiles): ?>

  <div class="empty">
    <p><strong>No image files found.</strong></p>
    <p>
      Put menu photographs in <code>smarteats/uploads/menu/</code> and
      restaurant logos in <code>smarteats/uploads/logos/</code>, then reload
      this page.
    </p>
  </div>

<?php else: ?>

  <?php /* ---------------- Restaurant logos ---------------- */ ?>
  <h2>Restaurant logos</h2>

  <?php if (!$logoFiles): ?>
    <div class="alert alert--info" role="status">
      No files in <code>uploads/logos/</code> yet. Copy some in and reload,
      or upload one at a time from <a href="<?= url('admin/settings.php') ?>">Settings</a>.
      A restaurant with no logo shows its initials instead, which looks
      deliberate rather than broken.
    </div>
  <?php else: ?>
    <form method="post" style="margin-bottom:2.5rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_logos">

      <div class="table-scroll">
        <table class="data">
          <thead>
            <tr><th>Current</th><th>Restaurant</th><th>Logo file</th></tr>
          </thead>
          <tbody>
            <?php foreach ($restaurants as $restaurant): ?>
              <?php
                $current = (string) $restaurant['logo'];
                $suggest = ($guessing && $current === '')
                    ? guess_file($restaurant['name'], $logoFiles)
                    : $current;
              ?>
              <tr>
                <td style="width:64px">
                  <?php if ($current && in_array($current, $logoFiles, true)): ?>
                    <img src="<?= UPLOADS_URL ?>/logos/<?= e(rawurlencode($current)) ?>" alt=""
                         style="width:48px;height:48px;object-fit:cover;border-radius:8px">
                  <?php else: ?>
                    <span class="badge">None</span>
                  <?php endif; ?>
                </td>
                <td><strong><?= e($restaurant['name']) ?></strong></td>
                <td>
                  <label class="sr-only" for="logo-<?= (int) $restaurant['id'] ?>">
                    Logo for <?= e($restaurant['name']) ?>
                  </label>
                  <select id="logo-<?= (int) $restaurant['id'] ?>"
                          name="logo[<?= (int) $restaurant['id'] ?>]">
                    <option value="">No logo</option>
                    <?php foreach ($logoFiles as $file): ?>
                      <option value="<?= e($file) ?>" <?= $suggest === $file ? 'selected' : '' ?>>
                        <?= e($file) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="row" style="margin-top:1rem">
        <button class="btn btn--accent" type="submit">Save logos</button>
      </div>
    </form>
  <?php endif; ?>

  <?php /* ---------------- Menu photos ---------------- */ ?>
  <div class="row row--between" style="margin-top:1rem">
    <h2 style="margin:0">Menu photos</h2>
    <div class="row" style="gap:.5rem">
      <?php if (!$guessing): ?>
        <a class="btn btn--sm btn--ghost" href="<?= url('attach_images.php?guess=1') ?>">
          Suggest matches by filename
        </a>
      <?php else: ?>
        <a class="btn btn--sm btn--ghost" href="<?= url('attach_images.php') ?>">
          Clear suggestions
        </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($guessing): ?>
    <div class="alert alert--info" role="status">
      <strong>These are suggestions, not saved yet.</strong>
      Every dish without a photo has been matched to the closest filename,
      ignoring case, spaces, dashes and the extension. Check them, correct any
      that are wrong, then press <em>Save menu photos</em>.
    </div>
  <?php endif; ?>

  <?php if (!$menuFiles): ?>
    <div class="alert alert--info" role="status">
      No files in <code>uploads/menu/</code> yet.
    </div>
  <?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_dishes">

      <?php foreach ($grouped as $restaurantName => $items): ?>
        <h3 style="margin-top:1.75rem"><?= e($restaurantName) ?></h3>

        <div class="table-scroll">
          <table class="data">
            <thead>
              <tr><th>Current</th><th>Dish</th><th>Category</th><th>Image file</th></tr>
            </thead>
            <tbody>
              <?php foreach ($items as $dish): ?>
                <?php
                  $current = (string) $dish['image'];
                  $suggest = ($guessing && $current === '')
                      ? guess_file($dish['name'], $menuFiles)
                      : $current;
                  $missing = $current !== '' && !in_array($current, $menuFiles, true);
                ?>
                <tr>
                  <td style="width:76px">
                    <?php if ($current && !$missing): ?>
                      <img src="<?= UPLOADS_URL ?>/menu/<?= e(rawurlencode($current)) ?>" alt=""
                           style="width:64px;height:48px;object-fit:cover;border-radius:6px">
                    <?php elseif ($missing): ?>
                      <span class="badge badge--cancelled">File gone</span>
                    <?php else: ?>
                      <span class="badge">None</span>
                    <?php endif; ?>
                  </td>
                  <td><strong><?= e($dish['name']) ?></strong></td>
                  <td class="text-muted"><?= e($dish['category_name']) ?></td>
                  <td>
                    <label class="sr-only" for="dish-<?= (int) $dish['id'] ?>">
                      Image for <?= e($dish['name']) ?>
                    </label>
                    <select id="dish-<?= (int) $dish['id'] ?>"
                            name="dish[<?= (int) $dish['id'] ?>]">
                      <option value="">No photo</option>
                      <?php foreach ($menuFiles as $file): ?>
                        <option value="<?= e($file) ?>" <?= $suggest === $file ? 'selected' : '' ?>>
                          <?= e($file) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>

      <div class="row" style="margin-top:1.25rem">
        <button class="btn btn--accent btn--lg" type="submit">Save menu photos</button>
        <a class="btn btn--ghost btn--lg" href="<?= url('admin/menu.php') ?>">Back to menu items</a>
      </div>
    </form>
  <?php endif; ?>

  <?php if ($unusedMenu): ?>
    <div class="card" style="margin-top:2rem">
      <h2 class="card__title">Files in uploads/menu not used by any dish</h2>
      <p class="text-muted" style="font-size:.9rem">
        Either a dish still needs one of these, or they belong to something that
        has since been withdrawn from the menu.
      </p>
      <div class="row" style="gap:1rem;flex-wrap:wrap">
        <?php foreach (array_slice($unusedMenu, 0, 24) as $file): ?>
          <figure style="margin:0;text-align:center;width:110px">
            <img src="<?= UPLOADS_URL ?>/menu/<?= e(rawurlencode($file)) ?>" alt=""
                 style="width:110px;height:82px;object-fit:cover;border-radius:6px">
            <figcaption class="text-muted" style="font-size:.72rem;word-break:break-all">
              <?= e($file) ?>
            </figcaption>
          </figure>
        <?php endforeach; ?>
      </div>
      <?php if (count($unusedMenu) > 24): ?>
        <p class="text-muted" style="font-size:.86rem">
          and <?= count($unusedMenu) - 24 ?> more.
        </p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<?php endif; ?>

<div class="alert alert--warning" role="alert" style="margin-top:2rem">
  <strong>Delete this file when the images are attached.</strong>
  Remove <code>attach_images.php</code> from the project folder. From then on,
  photos are uploaded one at a time from
  <a href="<?= url('admin/menu.php') ?>">Menu items</a> and
  <a href="<?= url('admin/settings.php') ?>">Settings</a>, which is the route
  the system is meant to be used through.
</div>

<?php include __DIR__ . '/includes/panel_footer.php'; ?>
