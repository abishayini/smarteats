<?php
/**
 * Smart Eats - shared staff, vendor and admin panel header
 *
 * Used by everything under /staff and /admin. The calling page must have
 * already run require_role(), so this file assumes an authorised user.
 *
 * The navigation is built from the role rather than the folder, because
 * vendors and platform administrators share the same screens. A vendor
 * sees their own restaurant's management links; an administrator sees
 * those plus the platform links, and a switcher that decides which
 * restaurant the management screens are currently acting on.
 *
 * Optional variables:
 *   $page_title - shown in the panel topbar and browser title
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/restaurants.php';

$page_title = $page_title ?? 'Dashboard';
$user       = current_user();
$role       = $user['role'] ?? 'guest';
$isAdmin    = $role === 'admin';
$isVendor   = $role === 'vendor';
$isStaff    = $role === 'staff';
$current    = basename($_SERVER['PHP_SELF']);
$section    = basename(dirname($_SERVER['PHP_SELF']));
$panelVendor = panel_restaurant();

// The switcher posts back to whichever page is showing it. Panel pages
// call handle_restaurant_switch() themselves before their own POST
// handling; this call covers any that reach the header first.
handle_restaurant_switch();

$switchable = $isAdmin
    ? db_all('SELECT id, name, approval_status FROM restaurants ORDER BY name')
    : [];

$pendingCount = $isAdmin
    ? (int) db_value("SELECT COUNT(*) FROM restaurants WHERE approval_status = 'pending'")
    : 0;

/** Sidebar link helper: marks the current page. */
function panel_link(string $href, string $label, bool $active, string $badge = ''): string
{
    return '<li><a href="' . url($href) . '"'
         . ($active ? ' class="is-active" aria-current="page"' : '')
         . '>' . e($label)
         . ($badge !== '' ? ' <span class="panel__badge">' . e($badge) . '</span>' : '')
         . '</a></li>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?> | <?= e($panelVendor['name'] ?? setting('platform_name', 'Smart Eats')) ?> panel</title>
<meta name="robots" content="noindex">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= ASSETS_URL ?>/css/style.css">
<link rel="stylesheet" href="<?= ASSETS_URL ?>/css/panel.css">
<script>window.SMARTEATS = { baseUrl: <?= json_encode(BASE_URL) ?>, csrf: <?= json_encode(csrf_token()) ?> };</script>
</head>
<body class="panel-body">

<a class="skip-link" href="#panel-main">Skip to content</a>

<div class="panel">

  <aside class="panel__side">
    <a class="panel__brand" href="<?= url(home_for_role($role)) ?>">
      <span class="brand__mark">SE</span>
      <span><?= e(setting('platform_name', 'Smart Eats')) ?></span>
    </a>

    <?php if ($panelVendor): ?>
      <p class="panel__scope">
        <span class="panel__scope-label"><?= $isAdmin ? 'Viewing' : 'Your restaurant' ?></span>
        <strong><?= e($panelVendor['name']) ?></strong>
        <?php if ($panelVendor['approval_status'] !== 'approved'): ?>
          <span class="badge badge--pending"><?= e(ucfirst($panelVendor['approval_status'])) ?></span>
        <?php elseif ((int) $panelVendor['is_accepting_orders'] !== 1): ?>
          <span class="badge badge--cancelled">Paused</span>
        <?php endif; ?>
      </p>
    <?php elseif ($isAdmin): ?>
      <p class="panel__scope">
        <span class="panel__scope-label">Viewing</span>
        <strong>All restaurants</strong>
      </p>
    <?php endif; ?>

    <?php if ($isAdmin && $switchable): ?>
      <form class="panel__switch" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="switch_restaurant">
        <label class="sr-only" for="panel-restaurant">Restaurant</label>
        <select id="panel-restaurant" name="restaurant_id" onchange="this.form.submit()">
          <option value="0">All restaurants</option>
          <?php foreach ($switchable as $option): ?>
            <option value="<?= (int) $option['id'] ?>"
                    <?= $panelVendor && (int) $panelVendor['id'] === (int) $option['id'] ? 'selected' : '' ?>>
              <?= e($option['name']) ?><?= $option['approval_status'] !== 'approved' ? ' (' . e($option['approval_status']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn--sm btn--ghost" type="submit">Switch</button></noscript>
      </form>
    <?php endif; ?>

    <nav aria-label="Panel">
      <p class="panel__navlabel">Orders</p>
      <ul class="panel__nav">
        <?= panel_link('staff/dashboard.php', 'Live orders', $current === 'dashboard.php' && $section === 'staff') ?>
        <?php if ($isAdmin || $isVendor): ?>
          <?= panel_link('admin/orders.php',  'Order history', $current === 'orders.php') ?>
          <?= panel_link('admin/reports.php', 'Reports',       $current === 'reports.php') ?>
        <?php endif; ?>
      </ul>

      <?php if ($isAdmin || $isVendor): ?>
        <p class="panel__navlabel"><?= $isVendor ? 'My restaurant' : 'Restaurant' ?></p>
        <ul class="panel__nav">
          <?= panel_link('admin/dashboard.php',  'Overview',   $current === 'dashboard.php' && $section === 'admin') ?>
          <?= panel_link('admin/menu.php',       'Menu items', $current === 'menu.php' || $current === 'item_form.php') ?>
          <?= panel_link('admin/categories.php', 'Categories', $current === 'categories.php') ?>
          <?= panel_link('admin/hours.php',      'Opening hours', $current === 'hours.php') ?>
          <?= panel_link('admin/users.php',      'Staff',      $current === 'users.php') ?>
          <?= panel_link('admin/settings.php',   'Settings',   $current === 'settings.php') ?>
        </ul>
      <?php endif; ?>

      <?php if ($isAdmin): ?>
        <p class="panel__navlabel">Platform</p>
        <ul class="panel__nav">
          <?= panel_link('admin/restaurants.php', 'Restaurants', $current === 'restaurants.php',
                          $pendingCount ? $pendingCount . ' new' : '') ?>
          <?= panel_link('admin/payments.php',    'Card payments',     $current === 'payments.php') ?>
          <?= panel_link('admin/platform.php',    'Platform settings', $current === 'platform.php') ?>
        </ul>
      <?php endif; ?>

      <p class="panel__navlabel">Site</p>
      <ul class="panel__nav">
        <?php if ($panelVendor): ?>
          <?= panel_link('restaurant.php?r=' . urlencode($panelVendor['slug']), 'View my menu', false) ?>
        <?php endif; ?>
        <?= panel_link('index.php', 'View storefront', false) ?>
      </ul>
    </nav>
  </aside>

  <div class="panel__main">

    <header class="panel__top">
      <h1 class="panel__title"><?= e($page_title) ?></h1>
      <div class="panel__account">
        <span>
          <?= e($user['full_name']) ?> &middot;
          <?= e(match ($role) {
                'admin'  => 'Platform admin',
                'vendor' => 'Restaurant owner',
                'staff'  => 'Kitchen staff',
                default  => ucfirst($role),
              }) ?>
        </span>
        <a class="btn btn--ghost btn--sm" href="<?= url('logout.php') ?>">Sign out</a>
      </div>
    </header>

    <div aria-live="polite">
      <?php foreach (take_flashes() as $f): ?>
        <div class="alert alert--<?= e($f['type']) ?>"
             role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
    </div>

    <div class="panel__content" id="panel-main">
