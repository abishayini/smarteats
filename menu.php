<?php
/**
 * Smart Eats - search every dish on the platform
 *
 * FR-01 display digital menu, FR-22 identify the seller of every dish.
 *
 * A customer who knows what they want to eat but not who cooks it starts
 * here. Results are grouped under the restaurant that sells them, so a
 * dish is never shown without its seller attached, and each group links
 * straight to that restaurant's own menu.
 *
 * Ordering happens on the restaurant page rather than here, because a
 * basket belongs to one restaurant and a cross-restaurant results list
 * is exactly where that rule is easiest to break by accident.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/restaurants.php';

$page_title = 'All dishes';

$search  = input('q');
$cuisine = input('cuisine');

$sql = "SELECT m.id, m.name, m.description, m.price, m.image, m.is_available,
               c.name AS category_name,
               r.id AS restaurant_id, r.name AS restaurant_name,
               r.slug AS restaurant_slug, r.cuisine AS restaurant_cuisine,
               r.is_accepting_orders, r.min_order_value, r.delivery_fee,
               r.free_delivery_over
        FROM menu_items m
        JOIN categories c   ON c.id = m.category_id
        JOIN restaurants r  ON r.id = m.restaurant_id
        WHERE m.is_active = 1 AND c.is_active = 1
          AND r.approval_status = 'approved'";
$params = [];

if ($search !== '') {
    $sql .= ' AND (m.name LIKE ? OR m.description LIKE ? OR r.name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

if ($cuisine !== '') {
    $sql .= ' AND r.cuisine = ?';
    $params[] = $cuisine;
}

$sql .= ' ORDER BY r.is_accepting_orders DESC, r.name, m.is_available DESC, m.name';

// Without a search term the whole platform's menu would be a very long
// page, so an unfiltered visit shows a sample per restaurant instead.
$items = db_all($sql, $params);
$ratings = ratings_for(array_column($items, 'id'));

// Group by restaurant, preserving the order the query returned.
$groups = [];
foreach ($items as $item) {
    $groups[$item['restaurant_id']]['restaurant'] = [
        'id'                  => $item['restaurant_id'],
        'name'                => $item['restaurant_name'],
        'slug'                => $item['restaurant_slug'],
        'cuisine'             => $item['restaurant_cuisine'],
        'is_accepting_orders' => $item['is_accepting_orders'],
        'min_order_value'     => $item['min_order_value'],
        'delivery_fee'        => $item['delivery_fee'],
        'free_delivery_over'  => $item['free_delivery_over'],
        'approval_status'     => 'approved',
    ];
    $groups[$item['restaurant_id']]['items'][] = $item;
}

$showAll = $search !== '' || $cuisine !== '';
$cuisines = active_cuisines();

include __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap">

    <header class="menu-head">
      <div>
        <h1>Every dish on Smart Eats</h1>
        <p class="text-muted">
          <?php if ($search !== ''): ?>
            <?= count($items) ?> dish<?= count($items) === 1 ? '' : 'es' ?>
            for &ldquo;<?= e($search) ?>&rdquo; across <?= count($groups) ?>
            restaurant<?= count($groups) === 1 ? '' : 's' ?>
          <?php else: ?>
            Search across every kitchen, or
            <a href="<?= url('restaurants.php') ?>">browse restaurant by restaurant</a>.
          <?php endif; ?>
        </p>
      </div>

      <form class="menu-search" method="get" action="<?= url('menu.php') ?>" role="search">
        <?php if ($cuisine !== ''): ?>
          <input type="hidden" name="cuisine" value="<?= e($cuisine) ?>">
        <?php endif; ?>
        <label class="sr-only" for="q">Search every dish</label>
        <input type="search" id="q" name="q" value="<?= e($search) ?>"
               placeholder="Biryani, pizza, brownie">
        <button class="btn btn--sm" type="submit">Search</button>
      </form>
    </header>

    <nav class="chips" aria-label="Cuisines">
      <a class="chip<?= $cuisine === '' ? ' is-active' : '' ?>"
         href="<?= url('menu.php' . ($search !== '' ? '?q=' . urlencode($search) : '')) ?>">
        All cuisines
      </a>
      <?php foreach ($cuisines as $option): ?>
        <a class="chip<?= $cuisine === $option ? ' is-active' : '' ?>"
           href="<?= url('menu.php?' . http_build_query(array_filter(['q' => $search, 'cuisine' => $option]))) ?>">
          <?= e($option) ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if (!$groups): ?>

      <div class="empty">
        <p><strong>Nothing matched that search.</strong></p>
        <p>Try a different word, or <a href="<?= url('restaurants.php') ?>">browse the restaurants</a>.</p>
      </div>

    <?php else: ?>

      <?php foreach ($groups as $group): ?>
        <?php
          $restaurant = $group['restaurant'];
          $groupItems = $showAll ? $group['items'] : array_slice($group['items'], 0, 3);
          $hidden     = count($group['items']) - count($groupItems);
        ?>
        <section class="dish-group">

          <div class="dish-group__head">
            <div>
              <h2 class="dish-group__name">
                <a href="<?= url('restaurant.php?r=' . urlencode($restaurant['slug'])) ?>">
                  <?= e($restaurant['name']) ?>
                </a>
              </h2>
              <p class="text-muted" style="margin:.2rem 0 0;font-size:.88rem">
                <?= e($restaurant['cuisine']) ?>
                &middot; <?= e(restaurant_summary_line($restaurant)) ?>
                <?php if ((int) $restaurant['is_accepting_orders'] !== 1): ?>
                  &middot; <span class="badge badge--cancelled">Closed</span>
                <?php endif; ?>
              </p>
            </div>
            <a class="btn btn--sm btn--ghost"
               href="<?= url('restaurant.php?r=' . urlencode($restaurant['slug'])) ?>">
              See full menu
            </a>
          </div>

          <div class="menu-grid">
            <?php foreach ($groupItems as $item): ?>
              <article class="dish<?= $item['is_available'] ? '' : ' dish--out' ?>">

                <a class="dish__media" href="<?= url('item.php?id=' . (int) $item['id']) ?>"
                   tabindex="-1" aria-hidden="true">
                  <img src="<?= e(menu_image_url($item['image'])) ?>" alt="" loading="lazy">
                  <?php if (!$item['is_available']): ?>
                    <span class="dish__flag">Sold out</span>
                  <?php endif; ?>
                </a>

                <div class="dish__body">
                  <p class="dish__category"><?= e($item['category_name']) ?></p>
                  <h3 class="dish__name">
                    <a href="<?= url('item.php?id=' . (int) $item['id']) ?>"><?= e($item['name']) ?></a>
                  </h3>

                  <?php if (isset($ratings[$item['id']])): ?>
                    <p class="dish__rating">
                      <span aria-hidden="true">&#9733;</span>
                      <?= e($ratings[$item['id']]['average']) ?>
                      <span class="text-muted">(<?= (int) $ratings[$item['id']]['total'] ?>)</span>
                    </p>
                  <?php endif; ?>

                  <p class="dish__desc"><?= e(excerpt($item['description'], 90)) ?></p>

                  <div class="dish__foot">
                    <span class="dish__price"><?= e(money($item['price'])) ?></span>
                    <a class="btn btn--sm btn--ghost" href="<?= url('item.php?id=' . (int) $item['id']) ?>">
                      View dish
                    </a>
                  </div>
                </div>

              </article>
            <?php endforeach; ?>
          </div>

          <?php if ($hidden > 0): ?>
            <p class="text-muted" style="margin-top:.75rem;font-size:.9rem">
              <a href="<?= url('restaurant.php?r=' . urlencode($restaurant['slug'])) ?>">
                <?= $hidden ?> more dish<?= $hidden === 1 ? '' : 'es' ?> at <?= e($restaurant['name']) ?>
              </a>
            </p>
          <?php endif; ?>

        </section>
      <?php endforeach; ?>

    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
