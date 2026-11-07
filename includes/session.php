<?php
declare(strict_types=1);

// Start the session once, with safe cookie settings.
if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.use_strict_mode', '1');   // reject unknown session IDs (session fixation)
    ini_set('session.use_only_cookies', '1');  // never accept IDs from the URL

    session_name('FIXMATE_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,          // cookie lasts until the browser closes
        'path'     => '/',
        'secure'   => $isHttps,   // true automatically when served over HTTPS
        'httponly' => true,       // JavaScript cannot read the cookie
        'samesite' => 'Lax',      // basic cross-site request protection
    ]);
    session_start();

    // Log out after 2 hours without any activity.
    $idleLimit = 2 * 60 * 60;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $idleLimit) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = time();
}
