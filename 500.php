<?php
/**
 * Smart Eats - something went wrong
 *
 * Shown when the application fails. It deliberately does not use the
 * shared header, the database or the session, because whichever of those
 * failed is what brought the visitor here. Everything it needs is in
 * this one file.
 */

if (!headers_sent()) {
    http_response_code(500);
}

$phone = '';
$name  = 'Smart Eats';

// Try for the real platform details, but never let this attempt be the
// reason the error page also fails. These are platform-level values: a
// restaurant's own phone number is not reachable here, because the
// failure may be the very thing that stopped us knowing which
// restaurant the visitor was ordering from.
try {
    if (defined('ROOT_PATH') && function_exists('setting')) {
        $phone = setting('platform_phone', '');
        $name  = setting('platform_name', 'Smart Eats');
    }
} catch (Throwable $ignored) {
    // Fall back to the plain wording below.
}

$base = defined('BASE_URL') ? BASE_URL : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Something went wrong | <?= htmlspecialchars($name) ?></title>
<style>
  body {
    font: 16px/1.65 "Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    color: #1b211e; background: #f6f4ef;
    margin: 0; padding: 4rem 1.5rem;
  }
  .box { max-width: 560px; margin: 0 auto; text-align: center; }
  h1 { font-size: 1.75rem; margin: 0 0 .75rem; letter-spacing: -.01em; }
  p { margin: 0 0 1rem; color: #4a5450; }
  .mark {
    display: inline-grid; place-items: center;
    width: 52px; height: 52px; margin-bottom: 1.25rem;
    background: #245c4a; color: #fff;
    border-radius: 14px; font-size: 1.15rem; font-weight: 600;
  }
  .btn {
    display: inline-block; margin-top: .75rem;
    padding: .8rem 1.5rem;
    background: #d4552a; color: #fff;
    border-radius: 10px; text-decoration: none; font-weight: 500;
  }
  .note { margin-top: 2rem; font-size: .88rem; color: #6f7873; }
</style>
</head>
<body>
  <div class="box">
    <span class="mark">SE</span>

    <h1>Something went wrong at our end</h1>

    <p>
      This is our fault, not yours. If you were placing an order, nothing has
      been charged and nothing has been sent to the kitchen.
    </p>

    <p>Please try again in a moment.</p>

    <a class="btn" href="<?= htmlspecialchars($base) ?>/">Back to the restaurants</a>

    <?php if ($phone !== ''): ?>
      <p class="note">
        If you need help, call Smart Eats on <?= htmlspecialchars($phone) ?>,
        or phone the restaurant directly.
      </p>
    <?php endif; ?>
  </div>
</body>
</html>
