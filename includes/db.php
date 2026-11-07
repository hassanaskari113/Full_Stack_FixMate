<?php
declare(strict_types=1);

/**
 * Return the shared PDO connection (created once per request).
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = require BASE_PATH . '/config/database.php';
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['name'],
        $cfg['charset']
    );

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Make MySQL's NOW()/CURRENT_TIMESTAMP use the same timezone as PHP.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . config('db_timezone') . "'",
        ]);
    } catch (PDOException $e) {
        // Log the real reason privately; show only a generic message to visitors.
        error_log('FixMate DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Database connection failed.');
    }

    return $pdo;
}
