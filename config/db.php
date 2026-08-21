<?php
/**
 * Smart Eats - database connection
 *
 * PDO with prepared statements is used throughout the system so that all
 * user input is bound rather than concatenated into SQL. This addresses
 * the injection risk noted in the non-functional security requirements.
 */

require_once __DIR__ . '/config.php';

/**
 * Returns the shared PDO connection, creating it on first use.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_NAME,
        DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        if (APP_ENV === 'development') {
            die(
                '<h2>Database connection failed</h2>'
                . '<p>' . htmlspecialchars($e->getMessage()) . '</p>'
                . '<p>Check that MySQL is running in the XAMPP control panel '
                . 'and that the <code>smarteats</code> database has been imported.</p>'
            );
        }
        error_log('DB connection failed: ' . $e->getMessage());
        die('The service is temporarily unavailable. Please try again shortly.');
    }

    return $pdo;
}

/* ------------------------------------------------------------------ */
/* Small query helpers - they keep page scripts short and consistent.  */
/* ------------------------------------------------------------------ */

/** Run a statement and return the PDOStatement. */
function db_run(string $sql, array $params = []): PDOStatement
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        // 42S02 means a table is missing, which almost always means the
        // schema has not been imported yet. A stack trace is no help
        // here, so explain what to do instead.
        if ($e->getCode() === '42S02') {
            db_setup_hint($e->getMessage());
        }
        throw $e;
    }
}

/** Stop with instructions for importing the schema. */
function db_setup_hint(string $detail = ''): void
{
    http_response_code(503);
    $showDetail = APP_ENV === 'development' && $detail !== '';

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<title>Database not set up</title>'
       . '<style>body{font:16px/1.6 system-ui,sans-serif;max-width:640px;'
       . 'margin:4rem auto;padding:0 1.5rem;color:#1b211e}'
       . 'code{background:#f0ede6;padding:.15rem .35rem;border-radius:4px}'
       . 'li{margin-bottom:.4rem}</style></head><body>';
    echo '<h1>The database has not been set up yet</h1>';
    echo '<p>Smart Eats connected to MySQL, but the <code>' . DB_NAME
       . '</code> tables are missing.</p>';
    echo '<ol>'
       . '<li>Open <a href="http://localhost/phpmyadmin">phpMyAdmin</a>.</li>'
       . '<li>Choose the <strong>Import</strong> tab.</li>'
       . '<li>Select <code>sql/smarteats.sql</code> from this project folder '
       . 'and press <strong>Go</strong>.</li>'
       . '<li>Reload this page.</li>'
       . '</ol>';
    echo '<p>You do not need to create the database first; the script creates '
       . 'it if it is missing.</p>';

    if ($showDetail) {
        echo '<p style="color:#6f7873;font-size:.9rem">' . htmlspecialchars($detail) . '</p>';
    }

    echo '</body></html>';
    exit;
}

/** Fetch a single row, or null. */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Fetch all rows. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

/** Fetch a single scalar value, or null. */
function db_value(string $sql, array $params = [])
{
    $value = db_run($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

/** Insert and return the new id. */
function db_insert(string $sql, array $params = []): int
{
    db_run($sql, $params);
    return (int) db()->lastInsertId();
}
