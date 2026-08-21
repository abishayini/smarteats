<?php
/**
 * Smart Eats - shared customer footer
 * Closes the layout opened in header.php and loads the site JavaScript.
 */
?>
</main>

<footer class="site-footer">
  <div class="wrap site-footer__inner">

    <div class="site-footer__col">
      <h2 class="site-footer__brand"><?= e(setting('platform_name', 'Smart Eats')) ?></h2>
      <p><?= e(setting('platform_tagline')) ?></p>
      <p class="text-muted" style="font-size:.86rem">
        Independent restaurants, no commission taken from the kitchen.
      </p>
    </div>

    <div class="site-footer__col">
      <h3>Order</h3>
      <ul class="site-footer__links">
        <li><a href="<?= url('restaurants.php') ?>">Browse restaurants</a></li>
        <li><a href="<?= url('menu.php') ?>">Search every dish</a></li>
        <li><a href="<?= url('cart.php') ?>">Your basket</a></li>
        <li><a href="<?= url('track.php') ?>">Track an order</a></li>
      </ul>
    </div>

    <div class="site-footer__col">
      <h3>For restaurants</h3>
      <ul class="site-footer__links">
        <li><a href="<?= url('restaurant_register.php') ?>">List your restaurant</a></li>
        <li><a href="<?= url('login.php') ?>">Owner sign in</a></li>
      </ul>
    </div>

    <div class="site-footer__col">
      <h3>Get in touch</h3>
      <p><a href="tel:<?= e(preg_replace('/\s+/', '', setting('platform_phone'))) ?>"><?= e(setting('platform_phone')) ?></a></p>
      <p><a href="mailto:<?= e(setting('platform_email')) ?>"><?= e(setting('platform_email')) ?></a></p>
    </div>

  </div>

  <div class="wrap site-footer__legal">
    <p>&copy; <?= date('Y') ?> <?= e(setting('platform_name', 'Smart Eats')) ?>.
       Built for the LD6053 Computing Project, Northumbria University.</p>
  </div>
</footer>

<script src="<?= ASSETS_URL ?>/js/main.js"></script>
<script src="<?= ASSETS_URL ?>/js/cart.js"></script>
<script src="<?= ASSETS_URL ?>/js/track.js"></script>
</body>
</html>
