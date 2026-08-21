<?php
/**
 * Smart Eats - menu item management
 *
 * FR-14 add, edit and remove menu items, FR-15 mark items unavailable.
 *
 * The two availability controls are deliberately different. "Available
 * today" is the switch a kitchen uses several times a service when
 * something sells out; "withdraw" removes a dish from the menu without
 * deleting the row, so past orders that reference it stay intact.
 *
 * Every query on this screen is filtered by the restaurant in the panel
 * scope, and every action re-checks the record's own restaurant before
 * touching it, because an id in a form post is not a permission.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/restaurants.php';

require_role('admin', 'vendor');
require_active_restaurant();

// The sidebar switcher posts back to this page, so it is dealt with
// before this screen's own POST handling sees an action it cannot place.
handle_restaurant_switch();

$restaurant   = require_panel_restaurant();
$restaurantId = (int) $restaurant['id'];
$page_title   = 'Menu items';

if (is_post()) {
    verify_csrf();

    $action = input('action');
    $id     = (int) input('id');
    $item   = $id ? db_one('SELECT * FROM menu_items WHERE id = ?', [$id]) : null;

    if (!$item) {
        flash('That dish could not be found.', 'error');
        redirect('admin/menu.php');
    }

    // The dish must belong to a restaurant this account may manage.
    require_restaurant_access((int) $item['restaurant_id']);

    if ($action === 'availability') {
        $now = (int) $item['is_available'] === 1 ? 0 : 1;
        db_run('UPDATE menu_items SET is_available = ? WHERE id = ?', [$now, $id]);
        flash(
            $item['name'] . ($now ? ' is available again.' : ' is marked unavailable today.'),
            'success'
        );
    }

    if ($action === 'withdraw') {
        db_run('UPDATE menu_items SET is_active = 0, is_available = 0 WHERE id = ?', [$id]);
        flash($item['name'] . ' has been removed from the menu.', 'success');
    }

    if ($action === 'restore') {
        db_run('UPDATE menu_items SET is_active = 1 WHERE id = ?', [$id]);
        flash($item['name'] . ' is back on the menu.', 'success');
    }

    redirect('admin/menu.php' . (input('show') === 'withdrawn' ? '?show=withdrawn' : ''));
}

$showWithdrawn = input('show') === 'withdrawn';
$search        = input('q');
$categoryId    = (int) input('category');

$sql = 'SELECT m.*, c.name AS category_name
        FROM menu_items m
        JOIN categories c ON c.id = m.category_id
        WHERE m.restaurant_id = ? AND m.is_active = ?';
$params = [$restaurantId, $showWithdrawn ? 0 : 1];

if ($categoryId) {
    $sql .= ' AND m.category_id = ?';
    $params[] = $categoryId;
}

if ($search !== '') {
    $sql .= ' AND m.name LIKE ?';
    $params[] = '%' . $search . '%';
}

$sql .= ' ORDER BY c.sort_order, m.name';

$items = db_all($sql, $params);

$categories = db_all(
    'SELECT id, name FROM categories WHERE restaurant_id = ? ORDER BY sort_order',
    [$restaurantId]
);

$counts = db_one(
    'SELECT SUM(is_active = 1) AS live,
            SUM(is_active = 1 AND is_available = 0) AS sold_out,
            SUM(is_active = 0) AS withdrawn
     FROM menu_items WHERE restaurant_id = ?',
    [$restaurantId]
);

include __DIR__ . '/../includes/panel_header.php';
?>

<div class="row row--between" style="margin-bottom:1.5rem">
  <div>
    <p class="text-muted" style="margin:0">
      <?= (int) $counts['live'] ?> on the menu
      &middot; <?= (int) $counts['sold_out'] ?> unavailable today
      &middot; <?= (int) $counts['withdrawn'] ?> withdrawn
      &middot; <?= e($restaurant['name']) ?>
    </p>
  </div>
  <a class="btn btn--accent" href="<?= url('admin/item_form.php') ?>">Add a dish</a>
</div>

<?php if (!$categories): ?>
  <div class="alert alert--warning" role="status">
    <strong>No categories yet.</strong>
    A dish has to sit in a category, so
    <a href="<?= url('admin/categories.php') ?>">create one first</a>.
  </div>
<?php endif; ?>

<form class="card" method="get" style="margin-bottom:1.5rem">
  <?php if ($showWithdrawn): ?>
    <input type="hidden" name="show" value="withdrawn">
  <?php endif; ?>
  <div class="grid grid--3" style="gap:1rem">
    <div class="field" style="margin:0">
      <label for="q">Search</label>
      <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Dish name">
    </div>

    <div class="field" style="margin:0">
      <label for="category">Category</label>
      <select id="category" name="category">
        <option value="">Every category</option>
        <?php foreach ($categories as $category): ?>
          <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>>
            <?= e($category['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field" style="margin:0;display:flex;align-items:flex-end;gap:.5rem">
      <button class="btn" type="submit">Apply</button>
      <a class="btn btn--ghost" href="<?= url('admin/menu.php' . ($showWithdrawn ? '?show=withdrawn' : '')) ?>">Clear</a>
    </div>
  </div>
</form>

<nav class="chips" aria-label="Menu view" style="margin-bottom:1.25rem">
  <a class="chip<?= $showWithdrawn ? '' : ' is-active' ?>" href="<?= url('admin/menu.php') ?>">
    On the menu <span class="chip__count"><?= (int) $counts['live'] ?></span>
  </a>
  <a class="chip<?= $showWithdrawn ? ' is-active' : '' ?>" href="<?= url('admin/menu.php?show=withdrawn') ?>">
    Withdrawn <span class="chip__count"><?= (int) $counts['withdrawn'] ?></span>
  </a>
</nav>

<?php if (!$items): ?>

  <div class="empty">
    <p><strong><?= $showWithdrawn ? 'Nothing has been withdrawn.' : 'No dishes match that.' ?></strong></p>
    <?php if (!$showWithdrawn): ?>
      <p><a href="<?= url('admin/item_form.php') ?>">Add your first dish</a>.</p>
    <?php endif; ?>
  </div>

<?php else: ?>

  <div class="table-scroll">
    <table class="data">
      <thead>
        <tr>
          <th></th><th>Dish</th><th>Category</th>
          <th class="text-right">Price</th><th>Status</th><th class="text-right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $item): ?>
          <tr>
            <td style="width:64px">
              <img src="<?= e(menu_image_url($item['image'])) ?>" alt=""
                   style="width:56px;height:42px;object-fit:cover;border-radius:6px">
            </td>
            <td>
              <strong><?= e($item['name']) ?></strong><br>
              <span class="text-muted" style="font-size:.82rem">
                <?= e(excerpt($item['description'], 60)) ?>
              </span>
            </td>
            <td><?= e($item['category_name']) ?></td>
            <td class="text-right"><?= e(money($item['price'])) ?></td>
            <td>
              <?php if (!$item['is_active']): ?>
                <span class="badge badge--cancelled">Withdrawn</span>
              <?php elseif ($item['is_available']): ?>
                <span class="badge badge--completed">Available</span>
              <?php else: ?>
                <span class="badge badge--pending">Sold out</span>
              <?php endif; ?>
            </td>
            <td class="text-right">
              <div class="row" style="justify-content:flex-end;gap:.35rem">

                <?php if ($item['is_active']): ?>
                  <a class="btn btn--sm btn--ghost"
                     href="<?= url('admin/item_form.php?id=' . (int) $item['id']) ?>">Edit</a>

                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="availability">
                    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                    <button class="btn btn--sm btn--ghost" type="submit">
                      <?= $item['is_available'] ? 'Mark sold out' : 'Mark available' ?>
                    </button>
                  </form>

                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="withdraw">
                    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                    <button class="btn btn--sm btn--ghost" type="submit"
                            data-confirm="Remove <?= e($item['name']) ?> from the menu? Past orders keep it.">
                      Withdraw
                    </button>
                  </form>
                <?php else: ?>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="show" value="withdrawn">
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                    <button class="btn btn--sm btn--accent" type="submit">Put back</button>
                  </form>
                <?php endif; ?>

              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="text-muted" style="font-size:.86rem;margin-top:1rem">
    Dishes are withdrawn rather than deleted, because past orders reference
    them and a deleted row would leave a hole in the order history.
  </p>

<?php endif; ?>

<?php include __DIR__ . '/../includes/panel_footer.php'; ?>
