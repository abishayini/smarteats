<?php
/**
 * Smart Eats - shared customer header
 *
 * Every customer-facing page includes this file first and footer.php
 * last, so the navigation, branding and cart badge stay identical across
 * the whole site and only need to be edited in one place.
 *
 * From Phase 11 the branding is the platform's, not a single
 * restaurant's, and the basket badge names the restaurant it belongs to.
 * A customer holding a basket from Spice Route should never be in doubt
 * about whose kitchen is about to receive it.
 *
 * Optional variables a page may set before including:
 *   $page_title  - appended to the browser title
 *   $body_class  - extra class on <body>
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/restaurants.php';

$page_title = $page_title ?? '';
$body_class = $body_class ?? '';
$current    = basename($_SERVER['PHP_SELF']);
$user       = current_user();
$brand      = setting('platform_name', 'Smart Eats');
$basketVendor = cart_restaurant();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $page_title ? e($page_title) . ' | ' . e($brand) : e($brand) . ' | ' . e(setting('platform_tagline')) ?></title>
<meta name="description" content="Order online from independent local restaurants on <?= e($brand) ?>, and follow your order from the kitchen to your door.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= ASSETS_URL ?>/css/style.css">
<script>window.SMARTEATS = { baseUrl: <?= json_encode(BASE_URL) ?>, csrf: <?= json_encode(csrf_token()) ?> };</script>
</head>
<body class="<?= e($body_class) ?>">

<a class="skip-link" href="#main">Skip to content</a>

<div class="topbar">
  <div class="wrap topbar__inner">
    <span><?= e(setting('platform_tagline')) ?></span>
    <span><?= e(setting('platform_phone')) ?></span>
  </div>
</div>

<header class="site-header">
  <div class="wrap site-header__inner">

    <a class="brand" href="<?= url('index.php') ?>">
      <span class="brand__mark">SE</span>
      <span class="brand__text">
        <strong><?= e($brand) ?></strong>
        <small><?= e(setting('platform_tagline')) ?></small>
      </span>
    </a>

    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="primary-nav">
      <span class="nav-toggle__bars" aria-hidden="true"></span>
      <span class="sr-only">Menu</span>
    </button>

    <nav class="nav" id="primary-nav" aria-label="Main">
      <ul class="nav__list">
        <li><a href="<?= url('index.php') ?>" <?= $current === 'index.php' ? 'aria-current="page"' : '' ?>>Home</a></li>
        <li><a href="<?= url('restaurants.php') ?>" <?= in_array($current, ['restaurants.php', 'restaurant.php'], true) ? 'aria-current="page"' : '' ?>>Restaurants</a></li>
        <li><a href="<?= url('menu.php') ?>" <?= $current === 'menu.php' ? 'aria-current="page"' : '' ?>>All dishes</a></li>
        <li><a href="<?= url('track.php') ?>" <?= $current === 'track.php' ? 'aria-current="page"' : '' ?>>Track order</a></li>

        <?php if ($user && $user['role'] === 'customer'): ?>
          <li><a href="<?= url('my_orders.php') ?>" <?= $current === 'my_orders.php' ? 'aria-current="page"' : '' ?>>My orders</a></li>
        <?php endif; ?>

        <?php if ($user): ?>
          <li><a href="<?= url('account.php') ?>" <?= $current === 'account.php' ? 'aria-current="page"' : '' ?>>Account</a></li>
        <?php endif; ?>

        <?php if ($user && in_array($user['role'], ['staff', 'vendor', 'admin'], true)): ?>
          <li><a href="<?= url(home_for_role($user['role'])) ?>">Dashboard</a></li>
        <?php endif; ?>
      </ul>

      <div class="nav__actions">
        <a class="cart-link" href="<?= url('cart.php') ?>">
          Basket
          <span class="cart-link__count" data-cart-count><?= cart_count() ?></span>
        </a>

        <?php if ($user): ?>
          <span class="nav__user">Hi, <?= e(explode(' ', $user['full_name'])[0]) ?></span>
          <a class="btn btn--ghost btn--sm" href="<?= url('logout.php') ?>">Sign out</a>
        <?php else: ?>
          <a class="btn btn--ghost btn--sm" href="<?= url('login.php') ?>">Sign in</a>
        <?php endif; ?>
      </div>
    </nav>

  </div>
</header>

<?php // Which kitchen the basket belongs to, so it is never a surprise. ?>
<?php if ($basketVendor && cart_count() > 0): ?>
  <div class="basket-bar">
    <div class="wrap basket-bar__inner">
      <span>
        Your basket is with <a href="<?= e(restaurant_url($basketVendor)) ?>"><strong><?= e($basketVendor['name']) ?></strong></a>
      </span>
      <a href="<?= url('cart.php') ?>"><?= cart_count() ?> item<?= cart_count() === 1 ? '' : 's' ?> &rsaquo;</a>
    </div>
  </div>
<?php endif; ?>

<?php if (!platform_is_open()): ?>
  <div class="wrap">
    <div class="alert alert--warning" role="status">
      <strong>Smart Eats is paused for maintenance.</strong>
      You are welcome to browse the restaurants, and ordering will return shortly.
    </div>
  </div>
<?php endif; ?>

<?php $flashes = take_flashes(); ?>
<?php if ($flashes): ?>
  <div class="wrap" aria-live="polite">
    <?php foreach ($flashes as $f): ?>
      <div class="alert alert--<?= e($f['type']) ?>"
           role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<main id="main">
