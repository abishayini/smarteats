<?php
/**
 * Smart Eats - add or edit a dish
 *
 * One form serves both cases. Uploading a photo here replaces the SQL
 * editing that was needed before, and the previous image is deleted so
 * the uploads folder does not fill with orphaned files.
 *
 * Two things guard the restaurant boundary. Editing loads the dish and
 * checks its restaurant before showing anything, and the category
 * dropdown is built from the current restaurant's categories only, so a
 * posted category id is validated against that same list rather than
 * against every category on the platform.
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

$id   = (int) input('id');
$item = $id ? db_one('SELECT * FROM menu_items WHERE id = ?', [$id]) : null;

if ($id && !$item) {
    flash('That dish could not be found.', 'error');
    redirect('admin/menu.php');
}

if ($item) {
    // Editing by id, so the dish's own restaurant decides access.
    require_restaurant_access((int) $item['restaurant_id']);
}

$isEdit     = (bool) $item;
$page_title = $isEdit ? 'Edit ' . $item['name'] : 'Add a dish';

$categories = db_all(
    'SELECT id, name FROM categories
     WHERE is_active = 1 AND restaurant_id = ?
     ORDER BY sort_order',
    [$restaurantId]
);

if (!$categories) {
    flash('Create a category before adding dishes.', 'warning');
    redirect('admin/categories.php');
}

$values = [
    'name'         => $item['name']         ?? '',
    'description'  => $item['description']  ?? '',
    'price'        => $item['price']        ?? '',
    'category_id'  => $item['category_id']  ?? $categories[0]['id'],
    'prep_minutes' => $item['prep_minutes'] ?? 15,
    'is_available' => $item['is_available'] ?? 1,
];

$errors = [];

if (is_post()) {
    verify_csrf();

    $values['name']         = input('name');
    $values['description']  = input('description');
    $values['price']        = input('price');
    $values['category_id']  = (int) input('category_id');
    $values['prep_minutes'] = (int) input('prep_minutes');
    $values['is_available'] = isset($_POST['is_available']) ? 1 : 0;

    if ($values['name'] === '') {
        $errors['name'] = 'Enter the name of the dish.';
    } elseif (str_len($values['name']) > 150) {
        $errors['name'] = 'Keep the name under 150 characters.';
    }

    if ($values['price'] === '' || !is_numeric($values['price'])) {
        $errors['price'] = 'Enter a price, for example 9.95.';
    } elseif ((float) $values['price'] < 0) {
        $errors['price'] = 'A price cannot be negative.';
    } elseif ((float) $values['price'] > 999.99) {
        $errors['price'] = 'That price looks wrong. The maximum is 999.99.';
    }

    // The category must belong to this restaurant, not merely exist.
    if (!db_value(
        'SELECT 1 FROM categories WHERE id = ? AND restaurant_id = ?',
        [$values['category_id'], $restaurantId]
    )) {
        $errors['category_id'] = 'Choose one of your own categories.';
    }

    if ($values['prep_minutes'] < 1 || $values['prep_minutes'] > 180) {
        $errors['prep_minutes'] = 'Enter a preparation time between 1 and 180 minutes.';
    }

    // The image is optional on both add and edit.
    $newImage = null;
    $hasUpload = isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($hasUpload) {
        $upload = store_menu_image($_FILES['image']);
        if ($upload['ok']) {
            $newImage = $upload['filename'];
        } else {
            $errors['image'] = $upload['error'];
        }
    }

    if (!$errors) {
        if ($isEdit) {
            db_run(
                'UPDATE menu_items
                 SET category_id = ?, name = ?, description = ?, price = ?,
                     prep_minutes = ?, is_available = ?
                     ' . ($newImage ? ', image = ?' : '') . '
                 WHERE id = ? AND restaurant_id = ?',
                $newImage
                    ? [$values['category_id'], $values['name'], $values['description'] ?: null,
                       $values['price'], $values['prep_minutes'], $values['is_available'],
                       $newImage, $id, $restaurantId]
                    : [$values['category_id'], $values['name'], $values['description'] ?: null,
                       $values['price'], $values['prep_minutes'], $values['is_available'],
                       $id, $restaurantId]
            );

            if ($newImage && $item['image']) {
                delete_menu_image($item['image']);
            }

            flash($values['name'] . ' has been updated.', 'success');
        } else {
            db_insert(
                'INSERT INTO menu_items
                   (restaurant_id, category_id, name, description, price, image,
                    prep_minutes, is_available)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $restaurantId,          // never taken from the form
                    $values['category_id'], $values['name'],
                    $values['description'] ?: null, $values['price'],
                    $newImage, $values['prep_minutes'], $values['is_available'],
                ]
            );
            flash($values['name'] . ' has been added to the ' . $restaurant['name'] . ' menu.', 'success');
        }

        redirect('admin/menu.php');
    }
}

include __DIR__ . '/../includes/panel_header.php';
?>

<p style="margin-bottom:1.25rem">
  <a href="<?= url('admin/menu.php') ?>">&larr; Back to menu items</a>
</p>

<form method="post" enctype="multipart/form-data" data-once novalidate>
  <?= csrf_field() ?>

  <div class="grid grid--2" style="align-items:start">

    <div class="card">
      <h2 class="card__title">Details</h2>
      <p class="text-muted" style="margin-top:-.5rem;font-size:.9rem">
        This dish will appear on the <?= e($restaurant['name']) ?> menu.
      </p>

      <div class="<?= field_class($errors, 'name') ?>">
        <label for="name">Dish name</label>
        <input type="text" id="name" name="name" value="<?= e($values['name']) ?>" required autofocus>
        <?= field_error($errors, 'name') ?>
      </div>

      <div class="field">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="3"><?= e($values['description']) ?></textarea>
        <span class="field__hint">Shown on the menu card and the dish page.</span>
      </div>

      <div class="<?= field_class($errors, 'category_id') ?>">
        <label for="category_id">Category</label>
        <select id="category_id" name="category_id" required>
          <?php foreach ($categories as $category): ?>
            <option value="<?= (int) $category['id'] ?>"
                    <?= (int) $values['category_id'] === (int) $category['id'] ? 'selected' : '' ?>>
              <?= e($category['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="field__hint">Your own categories only.</span>
        <?= field_error($errors, 'category_id') ?>
      </div>

      <div class="row" style="gap:1rem;align-items:flex-start">
        <div class="<?= field_class($errors, 'price') ?>" style="flex:1">
          <label for="price">Price (<?= e(setting('currency_symbol', '£')) ?>)</label>
          <input type="number" id="price" name="price" value="<?= e($values['price']) ?>"
                 step="0.01" min="0" max="999.99" required>
          <?= field_error($errors, 'price') ?>
        </div>

        <div class="<?= field_class($errors, 'prep_minutes') ?>" style="flex:1">
          <label for="prep_minutes">Preparation time (minutes)</label>
          <input type="number" id="prep_minutes" name="prep_minutes"
                 value="<?= (int) $values['prep_minutes'] ?>" min="1" max="180" required>
          <?= field_error($errors, 'prep_minutes') ?>
        </div>
      </div>

      <div class="field">
        <label style="display:flex;align-items:center;gap:.5rem;font-weight:400">
          <input type="checkbox" name="is_available" value="1"
                 style="width:auto" <?= $values['is_available'] ? 'checked' : '' ?>>
          Available to order today
        </label>
      </div>
    </div>

    <div class="card">
      <h2 class="card__title">Photo</h2>

      <img src="<?= e(menu_image_url($item['image'] ?? null)) ?>" alt=""
           style="width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:var(--radius);margin-bottom:1rem">

      <div class="<?= field_class($errors, 'image') ?>">
        <label for="image"><?= $isEdit && $item['image'] ? 'Replace photo' : 'Upload a photo' ?></label>
        <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp">
        <span class="field__hint">
          JPG, PNG or WebP, under <?= round(MAX_UPLOAD_BYTES / 1048576, 1) ?> MB.
          Landscape 4:3 works best; around 800 by 600 pixels is plenty.
        </span>
        <?= field_error($errors, 'image') ?>
      </div>

      <?php if ($isEdit && $item['image']): ?>
        <p class="text-muted" style="font-size:.84rem">
          Uploading a new photo replaces the current one and deletes the old file.
        </p>
      <?php endif; ?>
    </div>

  </div>

  <div class="row" style="margin-top:1.5rem">
    <button class="btn btn--accent btn--lg" type="submit">
      <?= $isEdit ? 'Save changes' : 'Add to the menu' ?>
    </button>
    <a class="btn btn--ghost btn--lg" href="<?= url('admin/menu.php') ?>">Cancel</a>
  </div>
</form>

<?php include __DIR__ . '/../includes/panel_footer.php'; ?>
