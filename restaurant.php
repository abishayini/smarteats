<?php
/**
 * Smart Eats - one restaurant's menu
 *
 * FR-01 display digital menu, FR-05 filter menu by category,
 * FR-21 restaurant-wise menus.
 *
 * This is the page a customer spends most of their time on. Everything
 * shown here belongs to one restaurant: its categories, its dishes, its
 * delivery fee and its minimum order. The header carries the restaurant's
 * name and address so there is never a question about who is cooking.
 *
 * A restaurant awaiting approval or suspended is a 404 to the public,
 * with one exception: its own owner and staff may preview it while they
 * build the menu, which is the whole point of the pending state.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/restaurants.php';
require_once __DIR__ . '/includes/hours.php';

$restaurant = restaurant_by_slug(input('r'));

// A private restaurant is visible to its own team and to platform
// administrators, and to nobody else.
$mayPreview = $restaurant
    && (has_role('admin')
        || (has_role('vendor', 'staff')
            && (int) (current_user()['restaurant_id'] ?? 0) === (int) $restaurant['id']));

if (!$restaurant || (!restaurant_is_public($restaurant) && !$mayPreview)) {
    http_response_code(404);
    $page_title = 'Restaurant not found';
    include __DIR__ . '/includes/header.php';
    echo '<section class="section"><div class="wrap"><div class="empty">'
       . '<p><strong>That restaurant is not on Smart Eats.</strong></p>'
       . '<p><a href="' . url('restaurants.php') . '">Browse the restaurants</a> instead.</p>'
       . '</div></div></section>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$page_title  = $restaurant['name'];
$restaurantId = (int) $restaurant['id'];
$activeSlug  = input('category');
$search      = input('q');
$isOpen      = restaurant_is_open($restaurant);
$closedNote  = restaurant_closed_reason($restaurant);
$rating      = restaurant_rating($restaurantId);
$service     = restaurant_service_rating($restaurantId);
$logo        = restaurant_logo_url($restaurant['logo']);
$hoursLine   = todays_hours_line($restaurant);
$weekHours   = (int) $restaurant['uses_schedule'] === 1
    ? weekly_hours_summary($restaurantId)
    : [];

// What customers said about the restaurant rather than about a dish.
$serviceReviews = db_all(
    'SELECT rr.rating, rr.comment, rr.created_at, u.full_name
     FROM restaurant_reviews rr
     LEFT JOIN users u ON u.id = rr.user_id
     WHERE rr.restaurant_id = ? AND rr.comment IS NOT NULL AND rr.comment <> \'\'
     ORDER BY rr.created_at DESC
     LIMIT 4',
    [$restaurantId]
);

// Categories belonging to this restaurant that actually hold a dish.
$categories = db_all(
    'SELECT c.id, c.name, c.slug, COUNT(m.id) AS item_count
     FROM categories c
     LEFT JOIN menu_items m
       ON m.category_id = c.id AND m.is_active = 1
     WHERE c.is_active = 1 AND c.restaurant_id = ?
     GROUP BY c.id, c.name, c.slug, c.sort_order
     HAVING item_count > 0
     ORDER BY c.sort_order',
    [$restaurantId]
);

$activeCategory = null;
foreach ($categories as $category) {
    if ($category['slug'] === $activeSlug) {
        $activeCategory = $category;
    }
}

// An unknown slug is treated as no filter rather than an error page.
if ($activeSlug !== '' && !$activeCategory) {
    $activeSlug = '';
}

$sql = 'SELECT m.id, m.name, m.description, m.price, m.image,
               m.is_available, m.prep_minutes,
               c.name AS category_name, c.slug AS category_slug
        FROM menu_items m
        JOIN categories c ON c.id = m.category_id
        WHERE m.is_active = 1 AND c.is_active = 1 AND m.restaurant_id = ?';
$params = [$restaurantId];

if ($activeCategory) {
    $sql .= ' AND c.id = ?';
    $params[] = $activeCategory['id'];
}

if ($search !== '') {
    $sql .= ' AND (m.name LIKE ? OR m.description LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

// Available dishes first, so anything sold out sinks to the bottom.
$sql .= ' ORDER BY m.is_available DESC, c.sort_order, m.name';

$items   = db_all($sql, $params);
$ratings = ratings_for(array_column($items, 'id'));

// Warn before the add button silently replaces a basket from elsewhere.
$otherBasket = cart_restaurant_id() && cart_restaurant_id() !== $restaurantId
    ? cart_restaurant()
    : null;

/** Build a URL for this restaurant that keeps the current search term. */
function vendor_menu_url(string $slug, string $categorySlug = '', string $search = ''): string
{
    $query = array_filter(
        ['r' => $slug, 'category' => $categorySlug, 'q' => $search],
        fn($v) => $v !== ''
    );
    return url('restaurant.php') . '?' . http_build_query($query);
}

include __DIR__ . '/includes/header.php';
?>

<section class="section--tight">
  <div class="wrap">

    <nav class="crumbs" aria-label="Breadcrumb">
      <a href="<?= url('restaurants.php') ?>">Restaurants</a>
      <span aria-hidden="true">&rsaquo;</span>
      <span aria-current="page"><?= e($restaurant['name']) ?></span>
    </nav>

    <header class="vendor-head">
      <?php if ($logo): ?>
        <img class="vendor-head__logo" src="<?= e($logo) ?>" alt="<?= e($restaurant['name']) ?> logo">
      <?php else: ?>
        <span class="vendor-head__logo vendor-head__logo--text" aria-hidden="true">
          <?= e(restaurant_monogram($restaurant['name'])) ?>
        </span>
      <?php endif; ?>

      <div class="vendor-head__body">
        <p class="vendor__cuisine"><?= e($restaurant['cuisine'] ?: 'Restaurant') ?></p>
        <h1><?= e($restaurant['name']) ?></h1>

        <?php if ($restaurant['tagline']): ?>
          <p class="vendor-head__tagline"><?= e($restaurant['tagline']) ?></p>
        <?php endif; ?>

        <ul class="vendor-head__facts">
          <?php if ($rating): ?>
            <li><span aria-hidden="true">&#9733;</span> <?= e($rating['average']) ?> out of 5
                <span class="text-muted">(<?= (int) $rating['total'] ?> reviews)</span></li>
          <?php endif; ?>
          <li><?= e(restaurant_summary_line($restaurant)) ?></li>
          <?php if ($restaurant['address']): ?>
            <li class="text-muted"><?= e($restaurant['address']) ?></li>
          <?php endif; ?>
          <?php if ($hoursLine !== ''): ?>
            <li class="text-muted"><?= e($hoursLine) ?></li>
          <?php endif; ?>
          <?php if ($service): ?>
            <li class="text-muted">
              Service <?= e($service['average']) ?> out of 5
              from <?= (int) $service['total'] ?>
              review<?= (int) $service['total'] === 1 ? '' : 's' ?>
            </li>
          <?php endif; ?>
          <?php if ($restaurant['phone']): ?>
            <li class="text-muted">
              <a href="tel:<?= e(preg_replace('/\s+/', '', $restaurant['phone'])) ?>"><?= e($restaurant['phone']) ?></a>
            </li>
          <?php endif; ?>
        </ul>
      </div>
    </header>

    <?php if (!restaurant_is_public($restaurant)): ?>
      <div class="alert alert--warning" role="status">
        <strong>Preview only.</strong>
        This restaurant is <?= e($restaurant['approval_status']) ?> and is not visible
        to customers. You can see it because you manage it.
      </div>
    <?php elseif (!$isOpen): ?>
      <div class="alert alert--warning" role="status"><?= e($closedNote) ?></div>
    <?php endif; ?>

    <?php if ($otherBasket): ?>
      <div class="alert alert--info" role="status">
        Your basket currently holds items from <strong><?= e($otherBasket['name']) ?></strong>.
        Adding a dish here will start a new basket, because an order goes to one
        kitchen. <a href="<?= url('cart.php') ?>">View your basket</a>
      </div>
    <?php endif; ?>

    <?php if ($restaurant['description']): ?>
      <p style="max-width:65ch"><?= e($restaurant['description']) ?></p>
    <?php endif; ?>

    <?php if ($weekHours || $serviceReviews): ?>
      <div class="grid grid--2" style="align-items:start;margin-top:1.5rem">

        <?php if ($weekHours): ?>
          <div class="card">
            <h2 class="card__title">Opening hours</h2>
            <table class="data" style="box-shadow:none">
              <tbody>
                <?php foreach ($weekHours as $line): ?>
                  <tr>
                    <th style="white-space:nowrap"><?= e($line['days']) ?></th>
                    <td class="text-right"><?= e($line['hours']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p class="text-muted" style="font-size:.86rem;margin:.85rem 0 0">
              Ordering opens and closes automatically with these hours.
            </p>
          </div>
        <?php endif; ?>

        <?php if ($serviceReviews): ?>
          <div class="card">
            <h2 class="card__title">What customers said about <?= e($restaurant['name']) ?></h2>
            <?php foreach ($serviceReviews as $review): ?>
              <div style="padding:.75rem 0;border-bottom:1px solid var(--line)">
                <p style="margin:0 0 .3rem">
                  <strong><?= e(explode(' ', (string) ($review['full_name'] ?? 'Customer'))[0]) ?></strong>
                  <span class="badge"><?= (int) $review['rating'] ?> out of 5</span>
                </p>
                <p style="margin:0 0 .25rem"><?= e($review['comment']) ?></p>
                <p class="text-muted" style="font-size:.8rem;margin:0">
                  <?= e(pretty_date($review['created_at'])) ?>
                </p>
              </div>
            <?php endforeach; ?>
            <p class="text-muted" style="font-size:.86rem;margin:.85rem 0 0">
              These come from customers who completed an order here.
            </p>
          </div>
        <?php endif; ?>

      </div>
    <?php endif; ?>

  </div>
</section>

<section class="section--tight" style="padding-bottom:3rem">
  <div class="wrap">

    <header class="menu-head">
      <div>
        <h2 style="margin:0"><?= $activeCategory ? e($activeCategory['name']) : 'Menu' ?></h2>
        <p class="text-muted" style="margin:.35rem 0 0">
          <?php if ($search !== ''): ?>
            <?= count($items) ?> result<?= count($items) === 1 ? '' : 's' ?>
            for &ldquo;<?= e($search) ?>&rdquo;
          <?php else: ?>
            Everything is cooked to order.
          <?php endif; ?>
        </p>
      </div>

      <form class="menu-search" method="get" action="<?= url('restaurant.php') ?>" role="search">
        <input type="hidden" name="r" value="<?= e($restaurant['slug']) ?>">
        <?php if ($activeSlug !== ''): ?>
          <input type="hidden" name="category" value="<?= e($activeSlug) ?>">
        <?php endif; ?>
        <label class="sr-only" for="q">Search this menu</label>
        <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Search dishes">
        <button class="btn btn--sm" type="submit">Search</button>
      </form>
    </header>

    <nav class="chips" aria-label="Menu categories">
      <a class="chip<?= $activeSlug === '' ? ' is-active' : '' ?>"
         href="<?= e(vendor_menu_url($restaurant['slug'], '', $search)) ?>">All dishes</a>
      <?php foreach ($categories as $category): ?>
        <a class="chip<?= $activeSlug === $category['slug'] ? ' is-active' : '' ?>"
           href="<?= e(vendor_menu_url($restaurant['slug'], $category['slug'], $search)) ?>"
           <?= $activeSlug === $category['slug'] ? 'aria-current="page"' : '' ?>>
          <?= e($category['name']) ?>
          <span class="chip__count"><?= (int) $category['item_count'] ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if (!$items): ?>
      <div class="empty">
        <p><strong><?= $search !== '' ? 'Nothing matched that search.' : 'This menu is still being built.' ?></strong></p>
        <p>
          <?php if ($search !== ''): ?>
            Try a different word, or
            <a href="<?= e(vendor_menu_url($restaurant['slug'])) ?>">see the full menu</a>.
          <?php else: ?>
            <a href="<?= url('restaurants.php') ?>">Browse other restaurants</a> in the meantime.
          <?php endif; ?>
        </p>
      </div>
    <?php else: ?>
      <div class="menu-grid">
        <?php foreach ($items as $item): ?>
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

              <p class="dish__desc"><?= e(excerpt($item['description'])) ?></p>

              <div class="dish__foot">
                <span class="dish__price"><?= e(money($item['price'])) ?></span>

                <?php if (!$isOpen): ?>
                  <span class="badge">Closed</span>
                <?php elseif ($item['is_available']): ?>
                  <button class="btn btn--sm btn--accent"
                          type="button"
                          data-add-to-cart
                          data-item-id="<?= (int) $item['id'] ?>"
                          data-item-name="<?= e($item['name']) ?>"
                          data-restaurant-name="<?= e($restaurant['name']) ?>">
                    Add to basket
                  </button>
                <?php else: ?>
                  <span class="badge">Unavailable today</span>
                <?php endif; ?>
              </div>
            </div>

          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
