<?php
/**
 * Smart Eats - error handling
 *
 * Turns every uncaught error into either a readable diagnostic during
 * development or a plain apology in production. Nothing about the
 * database, the file layout or the stack ever reaches a customer.
 *
 * Loaded by config.php, so it is active on every request.
 */

/** Write a message to the project's own log file. */
function log_problem(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    $path = ROOT_PATH . '/logs';

    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }

    @file_put_contents($path . '/error.log', $line, FILE_APPEND | LOCK_EX);
    error_log($message);
}

/**
 * Show the failure page. AJAX callers get JSON instead of HTML, since a
 * page of markup arriving where JSON was expected produces a second,
 * more confusing error in the browser.
 */
function fail_gracefully(string $detail = ''): void
{
    if (!headers_sent()) {
        http_response_code(500);
    }

    $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');

    if ($wantsJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'    => false,
            'error' => APP_ENV === 'development'
                ? $detail
                : 'Something went wrong. Please try again.',
        ]);
        exit;
    }

    // Discard any half-written page so the error is not appended to it.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (APP_ENV === 'development') {
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
           . '<title>Application error</title>'
           . '<style>body{font:15px/1.6 ui-monospace,Menlo,Consolas,monospace;'
           . 'max-width:900px;margin:3rem auto;padding:0 1.5rem;color:#1b211e}'
           . 'pre{background:#f0ede6;padding:1rem;border-radius:8px;'
           . 'overflow-x:auto;white-space:pre-wrap}</style></head><body>'
           . '<h1>Application error</h1>'
           . '<p>Shown because APP_ENV is set to development in config/config.php.</p>'
           . '<pre>' . htmlspecialchars($detail) . '</pre>'
           . '<p>Also written to <code>logs/error.log</code>.</p>'
           . '</body></html>';
        exit;
    }

    $page = ROOT_PATH . '/500.php';
    if (is_file($page)) {
        require $page;
    } else {
        echo 'Something went wrong. Please try again shortly.';
    }
    exit;
}

/* ------------------------------------------------------------------ */
/* Handlers                                                            */
/* ------------------------------------------------------------------ */

set_exception_handler(function (Throwable $e): void {
    log_problem(
        get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine()
    );

    fail_gracefully(
        get_class($e) . ': ' . $e->getMessage() . "\n\n"
        . 'in ' . $e->getFile() . ' on line ' . $e->getLine() . "\n\n"
        . $e->getTraceAsString()
    );
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    // Respect any @ suppression and the configured error_reporting level.
    if (!(error_reporting() & $severity)) {
        return false;
    }

    // Turn real errors into exceptions so they take the path above.
    // Notices and deprecations are logged but do not stop the page.
    if (in_array($severity, [E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_NOTICE, E_USER_DEPRECATED], true)) {
        log_problem("PHP $severity: $message in $file:$line");
        return true;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function (): void {
    $error = error_get_last();

    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        log_problem(
            'Fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']
        );
        fail_gracefully(
            $error['message'] . "\n\nin " . $error['file'] . ' on line ' . $error['line']
        );
    }
});
