<?php
/**
 * Smart Eats - page not found
 *
 * Referenced by ErrorDocument in .htaccess and used directly by pages
 * that cannot find what was asked for. A customer who mistypes a link
 * lands on the menu rather than an Apache error screen.
 */

require_once __DIR__ . '/includes/auth.php';

http_response_code(404);
$page_title = 'Page not found';

$popular = db_all(
    'SELECT id, name FROM menu_items
     WHERE is_active = 1 AND is_available = 1
     ORDER BY RAND() LIMIT 4'
);

include __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap" style="max-width:620px;text-align:center">

    <p class="text-muted" style="font-family:var(--font-display);font-size:3.5rem;
              line-height:1;margin-bottom:.5rem">404</p>

    <h1>We could not find that page</h1>
    <p>
      The link may be out of date, or the address may have a typo in it.
      Nothing has gone wrong with your order.
    </p>

    <div class="row" style="justify-content:center;margin:1.75rem 0">
      <a class="btn btn--accent" href="<?= url('menu.php') ?>">Browse the menu</a>
      <a class="btn btn--ghost" href="<?= url('track.php') ?>">Track an order</a>
    </div>

    <?php if ($popular): ?>
      <p class="text-muted" style="font-size:.9rem;margin-bottom:.4rem">Or try one of these</p>
      <p>
        <?php foreach ($popular as $index => $dish): ?>
          <a href="<?= url('item.php?id=' . (int) $dish['id']) ?>"><?= e($dish['name']) ?></a><?= $index < count($popular) - 1 ? ' &middot; ' : '' ?>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
