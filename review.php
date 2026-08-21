<?php
/**
 * Smart Eats - leave a review
 *
 * FR-07 ratings and reviews.
 *
 * Reviews are tied to a completed order, so every rating on the menu
 * comes from someone who actually received the dish. A dish can be rated
 * once per order, enforced by a unique key in the database as well as
 * here, so a double-submitted form cannot create two reviews.
 *
 * Each review also records the restaurant, which is what gives a
 * restaurant its own average rating on the directory without joining
 * back through the dish on every listing.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/orders.php';
require_once __DIR__ . '/includes/restaurants.php';

require_login();

$user  = current_user();
$order = order_by_number(input('order'));

if (!$order || (int) $order['user_id'] !== (int) $user['id']) {
    flash('That order could not be found.', 'error');
    redirect('my_orders.php');
}

if (!order_is_reviewable($order)) {
    flash('You can review an order once it has been completed.', 'info');
    redirect('my_orders.php');
}

$restaurant = restaurant_by_id((int) $order['restaurant_id']);
$page_title = 'Review order ' . $order['order_number'];
$errors     = [];

// One review of the restaurant per order, checked here as well as by the
// unique key, so the form can simply not offer it a second time.
$alreadyReviewedRestaurant = (bool) db_value(
    'SELECT 1 FROM restaurant_reviews WHERE order_id = ?',
    [$order['id']]
);

if (is_post()) {
    verify_csrf();

    $ratings  = $_POST['rating']  ?? [];
    $comments = $_POST['comment'] ?? [];
    $saved    = 0;

    // Only dishes still awaiting a review are accepted, so a resubmitted
    // form cannot overwrite or duplicate an existing one.
    $allowed = array_column(unreviewed_lines((int) $order['id']), 'menu_item_id');

    foreach ($ratings as $itemId => $rating) {
        $itemId = (int) $itemId;
        $rating = (int) $rating;

        if ($rating === 0) {
            continue;   // left blank, which is fine
        }

        if (!in_array($itemId, array_map('intval', $allowed), true)) {
            continue;
        }

        if ($rating < 1 || $rating > 5) {
            $errors['rating'] = 'Ratings must be between one and five stars.';
            continue;
        }

        $comment = trim((string) ($comments[$itemId] ?? ''));
        if (str_len($comment) > 500) {
            $comment = substr($comment, 0, 500);
        }

        try {
            db_insert(
                'INSERT INTO reviews (menu_item_id, restaurant_id, user_id, order_id, rating, comment)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $itemId,
                    $order['restaurant_id'],
                    $user['id'],
                    $order['id'],
                    $rating,
                    $comment !== '' ? $comment : null,
                ]
            );
            $saved++;
        } catch (PDOException $e) {
            // 23000 is the duplicate key from a resubmitted form.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    // The restaurant itself, which is a different question from the food.
    // A late delivery of an excellent lasagne is a complaint about the
    // restaurant, and the customer should be able to say exactly that.
    $serviceRating = (int) input('service_rating');

    if ($serviceRating >= 1 && $serviceRating <= 5 && !$alreadyReviewedRestaurant) {
        $speed = (int) input('speed_rating');
        $food  = (int) input('food_rating');
        $note  = trim((string) input('service_comment'));

        try {
            db_insert(
                'INSERT INTO restaurant_reviews
                   (restaurant_id, order_id, user_id, rating, food_rating, speed_rating, comment)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $order['restaurant_id'],
                    $order['id'],
                    $user['id'],
                    $serviceRating,
                    ($food  >= 1 && $food  <= 5) ? $food  : null,
                    ($speed >= 1 && $speed <= 5) ? $speed : null,
                    $note !== '' ? substr($note, 0, 500) : null,
                ]
            );
            $saved++;
        } catch (PDOException $e) {
            // Unique key on order_id: the form was submitted twice.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    } elseif ($serviceRating !== 0 && ($serviceRating < 1 || $serviceRating > 5)) {
        $errors['rating'] = 'Ratings must be between one and five stars.';
    }

    if ($saved) {
        flash(
            'Thank you. Your review of ' . $restaurant['name'] . ' has been saved.',
            'success'
        );
        redirect('my_orders.php');
    }

    if (!$errors) {
        $errors['rating'] = 'Choose a star rating for the restaurant or for at least one dish.';
    }
}

$lines = unreviewed_lines((int) $order['id']);

if (!$lines && $alreadyReviewedRestaurant) {
    flash('Everything on that order has already been reviewed.', 'info');
    redirect('my_orders.php');
}

include __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="wrap" style="max-width:640px">

    <h1>How was your order from <?= e($restaurant['name']) ?>?</h1>
    <p class="text-muted">
      Order <strong><?= e($order['order_number']) ?></strong>
      &middot; <?= e(pretty_date($order['created_at'])) ?>
    </p>

    <?php if (isset($errors['rating'])): ?>
      <div class="alert alert--error" role="alert"><?= e($errors['rating']) ?></div>
    <?php endif; ?>

    <form method="post" data-once>
      <?= csrf_field() ?>

      <?php if (!$alreadyReviewedRestaurant): ?>
        <div class="card" style="margin-bottom:1.5rem;border-left:4px solid var(--accent)">
          <h2 class="card__title" style="margin-bottom:.25rem">
            <?= e($restaurant['name']) ?>
          </h2>
          <p class="text-muted" style="margin-top:0;font-size:.9rem">
            How was the restaurant itself: the service, the packaging, and
            whether it arrived when it should have?
          </p>

          <fieldset class="stars">
            <legend class="label">Overall</legend>
            <?php for ($star = 5; $star >= 1; $star--): ?>
              <input type="radio" id="sr-<?= $star ?>" name="service_rating" value="<?= $star ?>">
              <label for="sr-<?= $star ?>">
                <span class="sr-only"><?= $star ?> star<?= $star === 1 ? '' : 's' ?></span>
                <span aria-hidden="true">&#9733;</span>
              </label>
            <?php endfor; ?>
          </fieldset>

          <div class="grid grid--2" style="gap:1rem;margin-top:1rem">
            <div class="field" style="margin:0">
              <label for="food_rating">Food quality <span class="text-muted">(optional)</span></label>
              <select id="food_rating" name="food_rating">
                <option value="">Not rated</option>
                <?php for ($star = 5; $star >= 1; $star--): ?>
                  <option value="<?= $star ?>"><?= $star ?> out of 5</option>
                <?php endfor; ?>
              </select>
            </div>

            <div class="field" style="margin:0">
              <label for="speed_rating">
                <?= $order['order_type'] === 'pickup' ? 'Ready on time' : 'Delivery time' ?>
                <span class="text-muted">(optional)</span>
              </label>
              <select id="speed_rating" name="speed_rating">
                <option value="">Not rated</option>
                <?php for ($star = 5; $star >= 1; $star--): ?>
                  <option value="<?= $star ?>"><?= $star ?> out of 5</option>
                <?php endfor; ?>
              </select>
            </div>
          </div>

          <div class="field" style="margin:1rem 0 0">
            <label for="service_comment">Comment <span class="text-muted">(optional)</span></label>
            <textarea id="service_comment" name="service_comment" rows="2" maxlength="500"
                      placeholder="Anything the restaurant should know?"></textarea>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($lines): ?>
        <h2 style="font-size:1.15rem;margin:1.5rem 0 .75rem">And the dishes</h2>
      <?php endif; ?>

      <?php foreach ($lines as $line): ?>
        <?php $itemId = (int) $line['menu_item_id']; ?>
        <div class="card" style="margin-bottom:1.25rem">

          <h2 class="card__title" style="margin-bottom:.75rem"><?= e($line['item_name']) ?></h2>

          <fieldset class="stars">
            <legend class="label">Your rating</legend>
            <?php for ($star = 5; $star >= 1; $star--): ?>
              <input type="radio"
                     id="r-<?= $itemId ?>-<?= $star ?>"
                     name="rating[<?= $itemId ?>]"
                     value="<?= $star ?>">
              <label for="r-<?= $itemId ?>-<?= $star ?>">
                <span class="sr-only"><?= $star ?> star<?= $star === 1 ? '' : 's' ?></span>
                <span aria-hidden="true">&#9733;</span>
              </label>
            <?php endfor; ?>
          </fieldset>

          <div class="field" style="margin:1rem 0 0">
            <label for="c-<?= $itemId ?>">Comment <span class="text-muted">(optional)</span></label>
            <textarea id="c-<?= $itemId ?>" name="comment[<?= $itemId ?>]"
                      rows="2" maxlength="500"
                      placeholder="What did you think?"></textarea>
          </div>

        </div>
      <?php endforeach; ?>

      <div class="row">
        <button class="btn btn--accent btn--lg" type="submit">Submit review</button>
        <a class="btn btn--ghost btn--lg" href="<?= url('my_orders.php') ?>">Not now</a>
      </div>

      <p class="text-muted" style="font-size:.86rem;margin-top:1rem">
        Leave any dish blank to skip it. Reviews appear on
        <?= e($restaurant['name']) ?>'s menu with your first name, and count
        towards the restaurant's rating.
      </p>
    </form>

  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
