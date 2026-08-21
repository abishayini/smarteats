<?php
/**
 * Smart Eats - restaurant directory
 *
 * FR-20 browse restaurants, FR-21 select a restaurant before ordering.
 *
 * This is the answer to the question a customer asks first on a platform
 * of several kitchens: who is cooking? Every dish on the site belongs to
 * one of the restaurants listed here, and a customer reaches a menu by
 * choosing a restaurant rather than by browsing an undifferentiated pile
 * of food.
 *
 * Only approved restaurants appear. One that registered but has not been
 * approved is invisible here, on the search, and on its own menu page.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/restaurants.php';

$page_title = 'Restaurants';

$search  = input('q');
$cuisine = input('cuisine');
$openOnly = input('open') === '1';

$restaurants = public_restaurants([
    'search'    => $search,
    'cuisine'   => $cuisine,
    'open_only' => $openOnly,
]);

$cuisines = active_cuisines();

/** Rebuild this page's URL with one filter changed. */
function directory_url(array $changes = []): string
{
    $query = array_merge(
        array_filter([
            'q'       => input('q'),
            'cuisine' => input('cuisine'),
            'open'    => input('open'),
        ], fn($v) => $v !== ''),
        $changes
    );
    $query = array_filter($query, fn($v) => $v !== '' && $v !== null);

    return url('restaurants.php') . ($query ? '?' . http_build_query($query) : '');
}

include __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap">

    <header class="menu-head">
      <div>
        <h1>Restaurants on Smart Eats</h1>
        <p class="text-muted">
          <?php if ($search !== '' || $cuisine !== ''): ?>
            <?= count($restaurants) ?> restaurant<?= count($restaurants) === 1 ? '' : 's' ?>
            <?= $search !== '' ? 'matching &ldquo;' . e($search) . '&rdquo;' : '' ?>
            <?= $cuisine !== '' ? 'serving ' . e($cuisine) : '' ?>
          <?php else: ?>
            Independent kitchens taking their own orders. Choose one to see its menu.
          <?php endif; ?>
        </p>
      </div>

      <form class="menu-search" method="get" action="<?= url('restaurants.php') ?>" role="search">
        <label class="sr-only" for="q">Search restaurants and dishes</label>
        <input type="search" id="q" name="q" value="<?= e($search) ?>"
               placeholder="Restaurant, cuisine or dish">
        <button class="btn btn--sm" type="submit">Search</button>
      </form>
    </header>

    <nav class="chips" aria-label="Cuisines">
      <a class="chip<?= $cuisine === '' ? ' is-active' : '' ?>"
         href="<?= e(directory_url(['cuisine' => null])) ?>">All cuisines</a>
      <?php foreach ($cuisines as $option): ?>
        <a class="chip<?= $cuisine === $option ? ' is-active' : '' ?>"
           href="<?= e(directory_url(['cuisine' => $option])) ?>"
           <?= $cuisine === $option ? 'aria-current="page"' : '' ?>>
          <?= e($option) ?>
        </a>
      <?php endforeach; ?>
      <a class="chip<?= $openOnly ? ' is-active' : '' ?>"
         href="<?= e(directory_url(['open' => $openOnly ? null : '1'])) ?>">
        Open now
      </a>
    </nav>

    <?php if (!$restaurants): ?>

      <div class="empty">
        <p><strong>No restaurants matched that.</strong></p>
        <p>Try a different word, or <a href="<?= url('restaurants.php') ?>">see every restaurant</a>.</p>
      </div>

    <?php else: ?>

      <div class="vendor-grid">
        <?php foreach ($restaurants as $restaurant): ?>
          <?php
            $open = restaurant_is_open($restaurant);
            $logo = restaurant_logo_url($restaurant['logo']);
          ?>
          <article class="vendor<?= $open ? '' : ' vendor--closed' ?>">

            <a class="vendor__head" href="<?= e(restaurant_url($restaurant)) ?>">
              <?php if ($logo): ?>
                <img class="vendor__logo" src="<?= e($logo) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="vendor__logo vendor__logo--text" aria-hidden="true">
                  <?= e(restaurant_monogram($restaurant['name'])) ?>
                </span>
              <?php endif; ?>

              <span class="vendor__identity">
                <span class="vendor__name"><?= e($restaurant['name']) ?></span>
                <span class="vendor__cuisine"><?= e($restaurant['cuisine'] ?: 'Restaurant') ?></span>
              </span>
            </a>

            <p class="vendor__desc"><?= e(excerpt($restaurant['description'] ?: $restaurant['tagline'], 110)) ?></p>

            <ul class="vendor__facts">
              <li><?= e(restaurant_summary_line($restaurant)) ?></li>
              <li><?= (int) $restaurant['dish_count'] ?> dishes
                  <?php if ($restaurant['rating_count']): ?>
                    &middot; <span aria-hidden="true">&#9733;</span>
                    <?= e($restaurant['rating_average']) ?>
                    (<?= (int) $restaurant['rating_count'] ?>)
                  <?php endif; ?>
              </li>
              <?php if ($restaurant['opening_hours']): ?>
                <li class="text-muted"><?= e($restaurant['opening_hours']) ?></li>
              <?php endif; ?>
            </ul>

            <div class="vendor__foot">
              <?php if ($open): ?>
                <span class="badge badge--completed">Taking orders</span>
              <?php else: ?>
                <span class="badge badge--cancelled">Not taking orders</span>
              <?php endif; ?>

              <a class="btn btn--sm <?= $open ? 'btn--accent' : 'btn--ghost' ?>"
                 href="<?= e(restaurant_url($restaurant)) ?>">
                <?= $open ? 'See the menu' : 'View restaurant' ?>
              </a>
            </div>

          </article>
        <?php endforeach; ?>
      </div>

    <?php endif; ?>

    <div class="card" style="margin-top:2.5rem">
      <h2 class="card__title">Run a restaurant?</h2>
      <p style="margin-bottom:1rem">
        Smart Eats takes no commission from your orders. List your menu, take
        orders on your own kitchen screen and keep the relationship with your
        customers.
      </p>
      <a class="btn btn--accent" href="<?= url('restaurant_register.php') ?>">
        List your restaurant
      </a>
    </div>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
