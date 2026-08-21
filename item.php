<?php
/**
 * Smart Eats - dish detail
 *
 * Shows one menu item with its full description, preparation time and
 * customer ratings, plus a quantity selector for adding to the basket.
 *
 * The restaurant selling the dish is named at the top, in the
 * breadcrumb, beside the price and in the related dishes, because this
 * page can be reached from a cross-restaurant search where the seller is
 * not otherwise obvious.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/restaurants.php';

$id = (int) input('id');

$item = $id ? db_one(
    'SELECT m.*, c.name AS category_name, c.slug AS category_slug
     FROM menu_items m
     JOIN categories c ON c.id = m.category_id
     WHERE m.id = ? AND m.is_active = 1 AND c.is_active = 1
     LIMIT 1',
    [$id]
) : null;

$restaurant = $item ? restaurant_by_id((int) $item['restaurant_id']) : null;

// A dish belonging to an unapproved restaurant is not public, even by
// direct link, unless the person manages that restaurant.
$mayPreview = $restaurant
    && (has_role('admin')
        || (has_role('vendor', 'staff')
            && (int) (current_user()['restaurant_id'] ?? 0) === (int) $restaurant['id']));

if (!$item || !$restaurant || (!restaurant_is_public($restaurant) && !$mayPreview)) {
    http_response_code(404);
    $page_title = 'Dish not found';
    include __DIR__ . '/includes/header.php';
    echo '<section class="section"><div class="wrap">';
    echo '<div class="empty"><p><strong>That dish is no longer on the menu.</strong></p>';
    echo '<p><a href="' . url('restaurants.php') . '">Browse the restaurants</a></p></div>';
    echo '</div></section>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$page_title = $item['name'] . ' at ' . $restaurant['name'];
$isOpen     = restaurant_is_open($restaurant);

$rating = db_one(
    'SELECT ROUND(AVG(rating), 1) AS average, COUNT(*) AS total
     FROM reviews WHERE menu_item_id = ?',
    [$item['id']]
);

$reviews = db_all(
    'SELECT r.rating, r.comment, r.created_at, u.full_name
     FROM reviews r
     LEFT JOIN users u ON u.id = r.user_id
     WHERE r.menu_item_id = ? AND r.comment IS NOT NULL AND r.comment <> \'\'
     ORDER BY r.created_at DESC
     LIMIT 5',
    [$item['id']]
);

// Related dishes come from the same restaurant only. Suggesting a side
// from another kitchen would be an invitation to break the one-basket
// rule at the exact moment the customer is least likely to notice.
$related = db_all(
    'SELECT id, name, price, image, is_available
     FROM menu_items
     WHERE category_id = ? AND restaurant_id = ? AND id <> ? AND is_active = 1
     ORDER BY is_available DESC, name
     LIMIT 3',
    [$item['category_id'], $restaurant['id'], $item['id']]
);

$otherBasket = cart_restaurant_id() && cart_restaurant_id() !== (int) $restaurant['id']
    ? cart_restaurant()
    : null;

include __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap">

    <nav class="crumbs" aria-label="Breadcrumb">
      <a href="<?= url('restaurants.php') ?>">Restaurants</a>
      <span aria-hidden="true">&rsaquo;</span>
      <a href="<?= e(restaurant_url($restaurant)) ?>"><?= e($restaurant['name']) ?></a>
      <span aria-hidden="true">&rsaquo;</span>
      <a href="<?= url('restaurant.php?r=' . urlencode($restaurant['slug']) . '&category=' . urlencode($item['category_slug'])) ?>">
        <?= e($item['category_name']) ?>
      </a>
      <span aria-hidden="true">&rsaquo;</span>
      <span aria-current="page"><?= e($item['name']) ?></span>
    </nav>

    <?php if ($otherBasket): ?>
      <div class="alert alert--info" role="status">
        Your basket currently holds items from <strong><?= e($otherBasket['name']) ?></strong>.
        Adding this dish will start a new basket with <?= e($restaurant['name']) ?>.
      </div>
    <?php endif; ?>

    <div class="dish-detail">

      <div class="dish-detail__media">
        <img src="<?= e(menu_image_url($item['image'])) ?>"
             alt="<?= e($item['name']) ?>">
      </div>

      <div class="dish-detail__info">
        <p class="dish__category">
          <a href="<?= e(restaurant_url($restaurant)) ?>"><?= e($restaurant['name']) ?></a>
          &middot; <?= e($item['category_name']) ?>
        </p>
        <h1><?= e($item['name']) ?></h1>

        <?php if ((int) $rating['total'] > 0): ?>
          <p class="text-muted" style="margin-top:-.5rem">
            Rated <?= e($rating['average']) ?> out of 5
            from <?= (int) $rating['total'] ?> review<?= (int) $rating['total'] === 1 ? '' : 's' ?>
          </p>
        <?php endif; ?>

        <p class="dish-detail__price"><?= e(money($item['price'])) ?></p>

        <?php if ($item['description']): ?>
          <p><?= e($item['description']) ?></p>
        <?php endif; ?>

        <p class="text-muted" style="font-size:.9rem">
          Usually ready in about <?= (int) $item['prep_minutes'] ?> minutes.
          <?php if ((float) $restaurant['min_order_value'] > 0): ?>
            <?= e($restaurant['name']) ?> has a
            <?= e(money($restaurant['min_order_value'])) ?> minimum order.
          <?php endif; ?>
        </p>

        <?php if (!$isOpen): ?>
          <div class="alert alert--warning" role="status" style="margin-top:1.5rem">
            <?= e(restaurant_closed_reason($restaurant)) ?>
          </div>
        <?php elseif ($item['is_available']): ?>
          <div class="qty-row">
            <label class="label" for="qty">Quantity</label>
            <div class="qty">
              <button class="qty__btn" type="button" data-qty-step="-1" aria-label="Reduce quantity">&minus;</button>
              <input class="qty__input" type="number" id="qty" value="1" min="1" max="20" inputmode="numeric">
              <button class="qty__btn" type="button" data-qty-step="1" aria-label="Increase quantity">+</button>
            </div>
          </div>

          <button class="btn btn--accent btn--lg" type="button"
                  data-add-to-cart
                  data-item-id="<?= (int) $item['id'] ?>"
                  data-item-name="<?= e($item['name']) ?>"
                  data-restaurant-name="<?= e($restaurant['name']) ?>"
                  data-qty-source="#qty">
            Add to basket
          </button>
        <?php else: ?>
          <div class="alert alert--warning" role="status" style="margin-top:1.5rem">
            This dish is unavailable today. <?= e($restaurant['name']) ?> updates
            availability through the day, so it may return later.
          </div>
        <?php endif; ?>

        <p style="margin-top:1.25rem">
          <a href="<?= e(restaurant_url($restaurant)) ?>">
            See the whole <?= e($restaurant['name']) ?> menu
          </a>
        </p>
      </div>

    </div>

    <?php if ($reviews): ?>
      <div style="margin-top:3rem;max-width:720px">
        <h2>What customers said</h2>
        <?php foreach ($reviews as $review): ?>
          <div class="card card--flat" style="margin-bottom:1rem">
            <p style="margin-bottom:.35rem">
              <strong><?= e($review['full_name'] ?? 'Customer') ?></strong>
              <span class="badge"><?= (int) $review['rating'] ?> out of 5</span>
            </p>
            <p style="margin-bottom:.25rem"><?= e($review['comment']) ?></p>
            <p class="text-muted" style="font-size:.82rem;margin:0">
              <?= e(pretty_date($review['created_at'])) ?>
            </p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($related): ?>
      <div style="margin-top:3rem">
        <h2>Also from <?= e($restaurant['name']) ?></h2>
        <div class="menu-grid">
          <?php foreach ($related as $other): ?>
            <article class="dish<?= $other['is_available'] ? '' : ' dish--out' ?>">
              <a class="dish__media" href="<?= url('item.php?id=' . (int) $other['id']) ?>"
                 tabindex="-1" aria-hidden="true">
                <img src="<?= e(menu_image_url($other['image'])) ?>" alt="" loading="lazy">
              </a>
              <div class="dish__body">
                <h3 class="dish__name">
                  <a href="<?= url('item.php?id=' . (int) $other['id']) ?>"><?= e($other['name']) ?></a>
                </h3>
                <div class="dish__foot">
                  <span class="dish__price"><?= e(money($other['price'])) ?></span>
                  <?php if ($isOpen && $other['is_available']): ?>
                    <button class="btn btn--sm btn--ghost" type="button"
                            data-add-to-cart
                            data-item-id="<?= (int) $other['id'] ?>"
                            data-item-name="<?= e($other['name']) ?>"
                            data-restaurant-name="<?= e($restaurant['name']) ?>">
                      Add
                    </button>
                  <?php else: ?>
                    <span class="badge"><?= $isOpen ? 'Sold out' : 'Closed' ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
