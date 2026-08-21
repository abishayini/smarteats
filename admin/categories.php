<?php
/**
 * Smart Eats - category management
 *
 * Categories drive the menu filter, so their order here is the order
 * customers see.
 *
 * From Phase 11 categories belong to a restaurant rather than to the
 * platform. A pizzeria has Antipasti and Pizza; a takeaway has Curries
 * and Breads; neither is forced into the other's headings. The slug is
 * unique within a restaurant, which is why two restaurants can both have
 * a category called Sides without colliding.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restaurants.php';

require_role('admin', 'vendor');
require_active_restaurant();

// The sidebar switcher posts back to this page, so it is dealt with
// before this screen's own POST handling sees an action it cannot place.
handle_restaurant_switch();

$restaurant   = require_panel_restaurant();
$restaurantId = (int) $restaurant['id'];

$page_title = 'Categories';
$errors     = [];
$editing    = null;

if (is_post()) {
    verify_csrf();

    $action = input('action');
    $id     = (int) input('id');

    // Any id arriving in the post is checked against this restaurant
    // before it is used, so a category from another restaurant cannot be
    // renamed or hidden from here.
    if ($id) {
        $owner = (int) db_value('SELECT restaurant_id FROM categories WHERE id = ?', [$id]);
        if (!$owner) {
            flash('That category could not be found.', 'error');
            redirect('admin/categories.php');
        }
        require_restaurant_access($owner);
    }

    if ($action === 'save') {
        $name  = input('name');
        $sort  = (int) input('sort_order');
        $slug  = make_slug($name);

        if ($name === '') {
            $errors['name'] = 'Enter a category name.';
        } elseif (str_len($name) > 80) {
            $errors['name'] = 'Keep the name under 80 characters.';
        } elseif ($slug === '') {
            $errors['name'] = 'Use at least one letter or number in the name.';
        } elseif (db_value(
            'SELECT 1 FROM categories WHERE slug = ? AND restaurant_id = ? AND id <> ?',
            [$slug, $restaurantId, $id]
        )) {
            $errors['name'] = 'One of your categories already uses that name.';
        }

        if (!$errors) {
            if ($id) {
                db_run(
                    'UPDATE categories SET name = ?, slug = ?, sort_order = ?
                     WHERE id = ? AND restaurant_id = ?',
                    [$name, $slug, $sort, $id, $restaurantId]
                );
                flash('Category updated.', 'success');
            } else {
                db_insert(
                    'INSERT INTO categories (restaurant_id, name, slug, sort_order)
                     VALUES (?, ?, ?, ?)',
                    [$restaurantId, $name, $slug, $sort]
                );
                flash($name . ' has been added.', 'success');
            }
            redirect('admin/categories.php');
        }

        $editing = ['id' => $id, 'name' => $name, 'sort_order' => $sort];
    }

    if ($action === 'toggle') {
        $category = db_one(
            'SELECT * FROM categories WHERE id = ? AND restaurant_id = ?',
            [$id, $restaurantId]
        );

        if ($category) {
            $now = (int) $category['is_active'] === 1 ? 0 : 1;

            // Hiding a category hides its dishes from the menu, so the
            // count is worth stating rather than leaving as a surprise.
            $dishCount = (int) db_value(
                'SELECT COUNT(*) FROM menu_items WHERE category_id = ? AND is_active = 1',
                [$id]
            );

            db_run('UPDATE categories SET is_active = ? WHERE id = ?', [$now, $id]);

            flash(
                $now
                    ? $category['name'] . ' is visible again.'
                    : $category['name'] . ' is hidden, along with ' . $dishCount . ' dish'
                      . ($dishCount === 1 ? '' : 'es') . '.',
                'success'
            );
        }

        redirect('admin/categories.php');
    }
}

if (!$editing && ($editId = (int) input('edit'))) {
    $editing = db_one(
        'SELECT * FROM categories WHERE id = ? AND restaurant_id = ?',
        [$editId, $restaurantId]
    );
    if (!$editing) {
        flash('That category belongs to another restaurant.', 'error');
        redirect('admin/categories.php');
    }
}

$categories = db_all(
    'SELECT c.*, COUNT(m.id) AS dish_count
     FROM categories c
     LEFT JOIN menu_items m ON m.category_id = c.id AND m.is_active = 1
     WHERE c.restaurant_id = ?
     GROUP BY c.id
     ORDER BY c.sort_order, c.name',
    [$restaurantId]
);

include __DIR__ . '/../includes/panel_header.php';
?>

<p class="text-muted" style="margin-bottom:1.25rem">
  Categories for <strong><?= e($restaurant['name']) ?></strong>. Other restaurants
  keep their own, so your headings are yours alone.
</p>

<div class="grid grid--2" style="align-items:start">

  <div>
    <?php if (!$categories): ?>
      <div class="empty">
        <p><strong>No categories yet.</strong></p>
        <p>Add your first one on the right, then start adding dishes to it.</p>
      </div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="data">
          <thead>
            <tr><th>Order</th><th>Category</th><th>Dishes</th><th>Status</th><th class="text-right">Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($categories as $category): ?>
              <tr>
                <td><?= (int) $category['sort_order'] ?></td>
                <td>
                  <strong><?= e($category['name']) ?></strong><br>
                  <span class="text-muted" style="font-size:.82rem"><?= e($category['slug']) ?></span>
                </td>
                <td><?= (int) $category['dish_count'] ?></td>
                <td>
                  <span class="badge badge--<?= $category['is_active'] ? 'completed' : 'pending' ?>">
                    <?= $category['is_active'] ? 'Visible' : 'Hidden' ?>
                  </span>
                </td>
                <td class="text-right">
                  <div class="row" style="justify-content:flex-end;gap:.35rem">
                    <a class="btn btn--sm btn--ghost"
                       href="<?= url('admin/categories.php?edit=' . (int) $category['id']) ?>">Edit</a>
                    <form method="post">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                      <button class="btn btn--sm btn--ghost" type="submit"
                              <?= $category['is_active'] && $category['dish_count']
                                  ? 'data-confirm="Hide ' . e($category['name']) . ' and its '
                                    . (int) $category['dish_count'] . ' dishes from the menu?"'
                                  : '' ?>>
                        <?= $category['is_active'] ? 'Hide' : 'Show' ?>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 class="card__title"><?= $editing ? 'Edit category' : 'Add a category' ?></h2>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

      <div class="<?= field_class($errors, 'name') ?>">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="<?= e($editing['name'] ?? '') ?>" required>
        <span class="field__hint">The web address is generated from this.</span>
        <?= field_error($errors, 'name') ?>
      </div>

      <div class="field">
        <label for="sort_order">Position</label>
        <input type="number" id="sort_order" name="sort_order"
               value="<?= (int) ($editing['sort_order'] ?? count($categories) + 1) ?>" min="0" max="99">
        <span class="field__hint">Lower numbers appear first on your menu.</span>
      </div>

      <div class="row">
        <button class="btn btn--accent" type="submit">
          <?= $editing ? 'Save changes' : 'Add category' ?>
        </button>
        <?php if ($editing): ?>
          <a class="btn btn--ghost" href="<?= url('admin/categories.php') ?>">Cancel</a>
        <?php endif; ?>
      </div>
    </form>

    <p class="text-muted" style="font-size:.84rem;margin:1.25rem 0 0">
      Categories are hidden rather than deleted, because past orders and
      existing dishes reference them.
    </p>
  </div>

</div>

<?php include __DIR__ . '/../includes/panel_footer.php'; ?>
