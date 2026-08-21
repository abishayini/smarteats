<?php
/**
 * Smart Eats - home page
 *
 * The platform landing page. Before Phase 11 this showed one kitchen's
 * categories; it now introduces the platform and sends the customer to
 * choose a restaurant, because on a site with several sellers the first
 * decision is who is cooking, not what to eat.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/restaurants.php';

$page_title = 'Home';

$restaurants = public_restaurants();
$cuisines    = active_cuisines();

$dishCount = (int) db_value(
    "SELECT COUNT(*) FROM menu_items m
     JOIN restaurants r ON r.id = m.restaurant_id
     WHERE m.is_active = 1 AND r.approval_status = 'approved'"
);

// A small, rotating sample so the page is not a wall of the same three
// dishes. Each carries its restaurant, which is the point of the page.
$featured = db_all(
    "SELECT m.id, m.name, m.description, m.price, m.image,
            r.name AS restaurant_name, r.slug AS restaurant_slug
     FROM menu_items m
     JOIN restaurants r ON r.id = m.restaurant_id
     WHERE m.is_active = 1 AND m.is_available = 1
       AND r.approval_status = 'approved' AND r.is_accepting_orders = 1
     ORDER BY RAND() LIMIT 3"
);

include __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap">
    <div class="grid grid--2" style="align-items:center">

      <div>
        <p class="text-muted" style="text-transform:uppercase;letter-spacing:.08em;font-size:.78rem;font-weight:600">
          <?= count($restaurants) ?> local restaurants &middot; <?= $dishCount ?> dishes
        </p>
        <h1>Order direct from the kitchen, with nothing in between.</h1>
        <p>
          Smart Eats lists independent restaurants that take their own orders.
          Choose a restaurant, build your basket and pay online. The order goes
          straight to that kitchen's screen, so there is no phone call to misread
          and no commission added to the bill.
        </p>
        <div class="row">
          <a class="btn btn--accent btn--lg" href="<?= url('restaurants.php') ?>">Browse restaurants</a>
          <a class="btn btn--ghost btn--lg" href="<?= url('track.php') ?>">Track an order</a>
        </div>
        <p class="text-muted" style="margin-top:1rem;font-size:.9rem">
          Every restaurant sets its own delivery fee and minimum order, shown
          before you add anything to your basket.
        </p>
      </div>

      <div class="card">
        <h2 class="card__title">Cooking tonight</h2>
        <p class="text-muted" style="font-size:.9rem">
          Each of these takes and cooks its own orders.
        </p>
        <table class="data" style="box-shadow:none">
          <tbody>
            <?php foreach (array_slice($restaurants, 0, 6) as $restaurant): ?>
              <tr>
                <td>
                  <a href="<?= e(restaurant_url($restaurant)) ?>"><?= e($restaurant['name']) ?></a><br>
                  <span class="text-muted" style="font-size:.82rem"><?= e($restaurant['cuisine']) ?></span>
                </td>
                <td class="text-right text-muted">
                  <?= restaurant_is_open($restaurant) ? 'Open' : 'Closed' ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$restaurants): ?>
              <tr><td class="text-muted">No restaurants have been approved yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    </div>
  </div>
</section>

<?php if ($cuisines): ?>
<section class="section--tight">
  <div class="wrap">
    <h2>Browse by cuisine</h2>
    <nav class="chips" aria-label="Cuisines">
      <?php foreach ($cuisines as $cuisine): ?>
        <a class="chip" href="<?= url('restaurants.php?cuisine=' . urlencode($cuisine)) ?>">
          <?= e($cuisine) ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
</section>
<?php endif; ?>

<?php if ($featured): ?>
<section class="section--tight" style="padding-bottom:2.5rem">
  <div class="wrap">
    <div class="row row--between" style="margin-bottom:1.25rem">
      <h2 style="margin:0">From the kitchens today</h2>
      <a href="<?= url('menu.php') ?>">Search every dish</a>
    </div>

    <div class="menu-grid">
      <?php foreach ($featured as $dish): ?>
        <article class="dish">
          <a class="dish__media" href="<?= url('item.php?id=' . (int) $dish['id']) ?>"
             tabindex="-1" aria-hidden="true">
            <img src="<?= e(menu_image_url($dish['image'])) ?>" alt="" loading="lazy">
          </a>
          <div class="dish__body">
            <p class="dish__category">
              <a href="<?= url('restaurant.php?r=' . urlencode($dish['restaurant_slug'])) ?>">
                <?= e($dish['restaurant_name']) ?>
              </a>
            </p>
            <h3 class="dish__name">
              <a href="<?= url('item.php?id=' . (int) $dish['id']) ?>"><?= e($dish['name']) ?></a>
            </h3>
            <p class="dish__desc"><?= e(excerpt($dish['description'], 90)) ?></p>
            <div class="dish__foot">
              <span class="dish__price"><?= e(money($dish['price'])) ?></span>
              <button class="btn btn--sm btn--accent" type="button"
                      data-add-to-cart
                      data-item-id="<?= (int) $dish['id'] ?>"
                      data-item-name="<?= e($dish['name']) ?>"
                      data-restaurant-name="<?= e($dish['restaurant_name']) ?>">
                Add to basket
              </button>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
