<?php
/**
 * Smart Eats - application configuration
 *
 * Every other file loads this first. Edit values here rather than
 * scattering constants through the codebase.
 */

// ---------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------
define('APP_ENV', 'development');   // 'development' or 'production'

if (APP_ENV === 'production') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

// ---------------------------------------------------------------------
// Paths and URLs
// ---------------------------------------------------------------------
// Root folder of the project on disk, no trailing slash.
define('ROOT_PATH', dirname(__DIR__));

// Public base URL, no trailing slash. Change if you rename the folder.
define('BASE_URL', 'http://localhost/smarteats');

define('ASSETS_URL',  BASE_URL . '/assets');
define('UPLOADS_URL', BASE_URL . '/uploads');
define('UPLOADS_PATH', ROOT_PATH . '/uploads');

// ---------------------------------------------------------------------
// Database (XAMPP defaults)
// ---------------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'smarteats');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------
// Session and security
// ---------------------------------------------------------------------
define('SESSION_NAME', 'smarteats_session');
define('SESSION_TIMEOUT', 1800);      // 30 minutes idle, NFR-08
define('PASSWORD_MIN_LENGTH', 8);

// ---------------------------------------------------------------------
// Stripe test keys (Phase 5)
// Get these from the Stripe dashboard in test mode. Never commit live keys.
// ---------------------------------------------------------------------
define('STRIPE_PUBLISHABLE_KEY', 'pk_test_51U2Dfu89Mf9uYwUMUFBUM9HTbatEq2lgzWvewWVqIPaszhXnEuhzc3l1fvK6LYKIAzzghvuIRneko4Ga7vgEr3Ob002mvv3XXO');
define('STRIPE_SECRET_KEY',      'sk_test_51U2Dfu89Mf9uYwUM1reOfTZK2E7FBbFZyhO6Xmq6tp0RrLKiHUiwvGgKQfdfpK8KCcsK4QuvKoVAtdwidTMgTTSt00Qc4IsTMr');
define('STRIPE_WEBHOOK_SECRET',  'whsec_replace_me');

// ---------------------------------------------------------------------
// Uploads
// ---------------------------------------------------------------------
define('MAX_UPLOAD_BYTES', 2 * 1024 * 1024);  // 2 MB
define('ALLOWED_IMAGE_TYPES', 'image/jpeg,image/png,image/webp');

// ---------------------------------------------------------------------
// Platform rules (Phase 11)
//
// A basket holds dishes from one restaurant at a time. Splitting an
// order across kitchens would mean several payments, several tickets and
// several delivery times behind one reference, which is a great deal of
// complexity for a case the platform does not need. Adding a dish from
// another restaurant offers to start a fresh basket instead.
// ---------------------------------------------------------------------
define('SINGLE_RESTAURANT_BASKET', true);

// A new restaurant must be approved by a platform administrator before
// customers can find it. Set to false only for local experimentation.
define('REQUIRE_RESTAURANT_APPROVAL', true);

// ---------------------------------------------------------------------
// Error handling
// Loaded last so it can use ROOT_PATH and APP_ENV.
// ---------------------------------------------------------------------
require_once ROOT_PATH . '/includes/errors.php';

// ---------------------------------------------------------------------
// Order status labels, used by both the customer and staff views
// ---------------------------------------------------------------------
$ORDER_STATUSES = [
    'pending'          => 'Awaiting confirmation',
    'confirmed'        => 'Confirmed',
    'preparing'        => 'Being prepared',
    'ready'            => 'Ready',
    'out_for_delivery' => 'Out for delivery',
    'completed'        => 'Completed',
    'cancelled'        => 'Cancelled',
];

// ---------------------------------------------------------------------
// Cuisine list used by restaurant registration and the browse filter
// ---------------------------------------------------------------------
$CUISINES = [
    'British', 'Indian', 'Italian', 'Chinese', 'Thai', 'Japanese',
    'Turkish', 'Caribbean', 'Mexican', 'Middle Eastern', 'Healthy',
    'Desserts', 'Other',
];
