<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

/**
 * Read a value from config/app.php.
 */
function config(string $key, mixed $default = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require BASE_PATH . '/config/app.php';
    }
    return $cfg[$key] ?? $default;
}

date_default_timezone_set(config('timezone', 'UTC'));

error_reporting(E_ALL);
if (config('env') === 'development') {
    ini_set('display_errors', '1');   // show errors while developing
} else {
    ini_set('display_errors', '0');   // never show errors to visitors in production
    ini_set('log_errors', '1');
}

require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/session.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/csrf.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/upload.php';
require_once BASE_PATH . '/includes/ui.php';
