<?php
/**
 * Smart Eats - basket
 *
 * FR-02 manage shopping cart.
 *
 * Every action on this page is handled server-side by a normal form
 * post. The JavaScript in cart.js intercepts the same controls to avoid
 * a page reload, so the page remains fully usable with scripting
 * disabled or blocked.
 *
 * A basket belongs to one restaurant, named at the top of the page and
 * beside the totals. The delivery fee, the minimum order and the VAT
 * rate all come from that restaurant rather than from a platform-wide
 * setting, so the figures here are the ones its own kitchen set.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/restaurants.php';

$page_title = 'Your basket';

if (is_post()) {
    verify_csrf();
    $action = input('action');

    switch ($action) {

        // Quantities are submitted as qty[itemId] so the whole basket can
        // be updated in a single request.
        case 'update':
            $quantities = $_POST['qty'] ?? [];
            $changed = 0;

            foreach ($quantities as $itemId => $quantity) {
                $itemId   = (int) $itemId;
                $quantity = (int) $quantity;

                if (!isset($_SESSION['cart'][$itemId])) {
                    continue;
                }

                if ($quantity < 1) {
                    unset($_SESSION['cart'][$itemId]);
                    $changed++;
                    continue;
                }

                $quantity = min($quantity, 20);
                if ((int) $_SESSION['cart'][$itemId] !== $quantity) {
                    $_SESSION['cart'][$itemId] = $quantity;
                    $changed++;
                }
            }

            if (!cart()) {
                cart_clear();
            }

            flash($changed ? 'Basket updated.' : 'No changes to save.', $changed ? 'success' : 'info');
            redirect('cart.php');
            break;

        case 'remove':
            $itemId = (int) input('item_id');
            $name   = db_value('SELECT name FROM menu_items WHERE id = ?', [$itemId]);
            unset($_SESSION['cart'][$itemId]);
            if (!cart()) {
                cart_clear();
            }
            flash(($name ?: 'Dish') . ' removed from your basket.', 'success');
            redirect('cart.php');
            break;

        case 'clear':
            cart_clear();
            flash('Your basket is empty.', 'info');
            redirect('cart.php');
            break;

        case 'order_type':
            set_order_type(input('order_type'));
            redirect('cart.php');
            break;
    }
}

$restaurant = cart_restaurant();
$items      = cart_items();
$orderType  = current_order_type();
$problems   = cart_problems();
$totals     = cart_totals($orderType);

// cart_problems() may drop withdrawn dishes or clear the basket, so
// everything is reloaded afterwards.
if ($problems) {
    $restaurant = cart_restaurant();
    $items      = cart_items();
    $totals     = cart_totals($orderType);
}

$freeOver    = $restaurant ? (float) $restaurant['free_delivery_over'] : 0.0;
$isOpen      = restaurant_is_open($restaurant);
$canCheckout = $items && !$problems && $isOpen;

include __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap">

    <h1>Your basket</h1>

    <?php if ($restaurant && $items): ?>
      <p class="text-muted">
        Ordering from
        <a href="<?= e(restaurant_url($restaurant)) ?>"><strong><?= e($restaurant['name']) ?></strong></a>
        <?php if ($restaurant['address']): ?>
          &middot; <?= e($restaurant['address']) ?>
        <?php endif; ?>
      </p>
    <?php endif; ?>

    <?php if (!$items): ?>

      <div class="empty">
        <p><strong>Your basket is empty.</strong></p>
        <p>Choose a restaurant, pick something from its menu, and it will appear here.</p>
        <p style="margin-top:1.25rem">
          <a class="btn btn--accent" href="<?= url('restaurants.php') ?>">Browse restaurants</a>
        </p>
      </div>

    <?php else: ?>

      <?php foreach ($problems as $problem): ?>
        <div class="alert alert--warning" role="alert"><?= e($problem) ?></div>
      <?php endforeach; ?>

      <div class="basket">

        <div class="basket__lines">
          <form method="post" action="<?= url('cart.php') ?>" data-cart-form>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">

            <?php foreach ($items as $item): ?>
              <div class="line<?= $item['is_available'] ? '' : ' line--out' ?>"
                   data-line
                   data-item-id="<?= (int) $item['id'] ?>"
                   data-unit-price="<?= e($item['price']) ?>">

                <a class="line__media" href="<?= url('item.php?id=' . (int) $item['id']) ?>"
                   tabindex="-1" aria-hidden="true">
                  <img src="<?= e(menu_image_url($item['image'])) ?>" alt="" loading="lazy">
                </a>

                <div class="line__info">
                  <h2 class="line__name">
                    <a href="<?= url('item.php?id=' . (int) $item['id']) ?>"><?= e($item['name']) ?></a>
                  </h2>
                  <p class="line__unit"><?= e(money($item['price'])) ?> each</p>
                  <?php if (!$item['is_available']): ?>
                    <p class="line__warn">Unavailable today</p>
                  <?php endif; ?>
                </div>

                <div class="line__qty">
                  <label class="sr-only" for="qty-<?= (int) $item['id'] ?>">
                    Quantity of <?= e($item['name']) ?>
                  </label>
                  <div class="qty">
                    <button class="qty__btn" type="button" data-qty-step="-1"
                            aria-label="Reduce quantity">&minus;</button>
                    <input class="qty__input" type="number"
                           id="qty-<?= (int) $item['id'] ?>"
                           name="qty[<?= (int) $item['id'] ?>]"
                           value="<?= (int) $item['quantity'] ?>"
                           min="0" max="20" inputmode="numeric">
                    <button class="qty__btn" type="button" data-qty-step="1"
                            aria-label="Increase quantity">+</button>
                  </div>
                </div>

                <div class="line__total" data-line-total>
                  <?= e(money($item['line_total'])) ?>
                </div>

                <button class="line__remove" type="submit"
                        form="remove-<?= (int) $item['id'] ?>"
                        data-remove
                        aria-label="Remove <?= e($item['name']) ?> from basket">
                  Remove
                </button>

              </div>
            <?php endforeach; ?>

            <div class="basket__actions">
              <button class="btn btn--ghost btn--sm" type="submit" data-update-basket>
                Update basket
              </button>
              <a href="<?= e(restaurant_url($restaurant)) ?>">
                Add more from <?= e($restaurant['name']) ?>
              </a>
            </div>
          </form>

          <?php // Separate forms so a remove button works without scripting. ?>
          <?php foreach ($items as $item): ?>
            <form id="remove-<?= (int) $item['id'] ?>" method="post"
                  action="<?= url('cart.php') ?>" hidden>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="remove">
              <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
            </form>
          <?php endforeach; ?>

          <form method="post" action="<?= url('cart.php') ?>" style="margin-top:1rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clear">
            <button class="link-button" type="submit"
                    data-confirm="Empty your whole basket?">Empty basket</button>
          </form>

          <p class="text-muted" style="font-size:.86rem;margin-top:1rem">
            An order goes to one kitchen. To order from another restaurant,
            complete this order first or empty the basket.
          </p>
        </div>

        <aside class="basket__summary">
          <div class="card">

            <h2 class="card__title">Order summary</h2>

            <p class="text-muted" style="margin-top:-.5rem;font-size:.9rem">
              <?= e($restaurant['name']) ?>
            </p>

            <form method="post" action="<?= url('cart.php') ?>" data-order-type-form>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="order_type">

              <fieldset class="switch">
                <legend class="sr-only">How would you like your order?</legend>

                <label class="switch__option<?= $orderType === 'delivery' ? ' is-active' : '' ?>">
                  <input type="radio" name="order_type" value="delivery"
                         <?= $orderType === 'delivery' ? 'checked' : '' ?>>
                  <span>Delivery</span>
                </label>

                <label class="switch__option<?= $orderType === 'pickup' ? ' is-active' : '' ?>">
                  <input type="radio" name="order_type" value="pickup"
                         <?= $orderType === 'pickup' ? 'checked' : '' ?>>
                  <span>Collection</span>
                </label>
              </fieldset>

              <noscript>
                <button class="btn btn--ghost btn--sm btn--full" type="submit"
                        style="margin-bottom:1rem">Apply</button>
              </noscript>
            </form>

            <dl class="totals">
              <div class="totals__row">
                <dt>Subtotal</dt>
                <dd data-total="subtotal"><?= e(money($totals['subtotal'])) ?></dd>
              </div>

              <div class="totals__row"<?= $orderType === 'pickup' ? ' hidden' : '' ?> data-fee-row>
                <dt>Delivery</dt>
                <dd data-total="delivery_fee">
                  <?= $totals['delivery_fee'] > 0 ? e(money($totals['delivery_fee'])) : 'Free' ?>
                </dd>
              </div>

              <?php if ($totals['tax'] > 0): ?>
                <div class="totals__row">
                  <dt>VAT</dt>
                  <dd data-total="tax"><?= e(money($totals['tax'])) ?></dd>
                </div>
              <?php endif; ?>

              <div class="totals__row totals__row--grand">
                <dt>Total</dt>
                <dd data-total="total"><?= e(money($totals['total'])) ?></dd>
              </div>
            </dl>

            <?php if ($orderType === 'delivery' && $freeOver > 0 && $totals['subtotal'] < $freeOver): ?>
              <p class="text-muted" style="font-size:.86rem">
                Spend <?= e(money($freeOver - $totals['subtotal'])) ?> more for free delivery
                from <?= e($restaurant['name']) ?>.
              </p>
            <?php endif; ?>

            <?php if ($canCheckout): ?>
              <a class="btn btn--accent btn--full btn--lg" href="<?= url('checkout.php') ?>">
                Continue to checkout
              </a>
            <?php else: ?>
              <button class="btn btn--full btn--lg" type="button" disabled>
                Continue to checkout
              </button>
              <p class="text-muted" style="font-size:.86rem;margin-top:.75rem;margin-bottom:0">
                <?= $isOpen
                    ? 'Resolve the notices above to continue.'
                    : 'This restaurant is not taking orders. Your basket is saved.' ?>
              </p>
            <?php endif; ?>

            <p class="text-muted" style="font-size:.82rem;margin:1rem 0 0">
              <?= $orderType === 'pickup'
                  ? 'Collect from ' . e($restaurant['address'])
                  : 'Delivery address is taken at checkout.' ?>
            </p>

          </div>
        </aside>

      </div>

    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
