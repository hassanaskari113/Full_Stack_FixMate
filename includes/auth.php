<?php
declare(strict_types=1);

/**
 * The logged-in user's row from the database, or null.
 * Re-checked from the database so deactivated users lose access immediately.
 */
function currentUser(bool $refresh = false): ?array
{
    static $user = false;

    if ($refresh) {
        $user = false;
    }
    if ($user !== false) {
        return $user;
    }

    $user = null;
    if (!empty($_SESSION['user_id'])) {
        $stmt = db()->prepare(
            'SELECT id, username, first_name, last_name, email, profile_picture,
                    phone_number, role, is_admin, is_active
             FROM users WHERE id = ?'
        );
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();

        if ($row && (int) $row['is_active'] === 1) {
            $user = $row;
        } else {
            unset($_SESSION['user_id']);   // deleted or deactivated
        }
    }
    return $user;
}

function isLoggedIn(): bool
{
    return currentUser() !== null;
}

function isAdmin(): bool
{
    $u = currentUser();
    return $u !== null && (int) $u['is_admin'] === 1;
}

/** Where each kind of user lands after login. */
function dashboardPath(array $user): string
{
    if ((int) $user['is_admin'] === 1) {
        return '/admin/dashboard.php';
    }
    return $user['role'] === 'P' ? '/provider/dashboard.php' : '/customer/dashboard.php';
}

/** Log a user in (call after the password has been verified). */
function loginUser(array $user): void
{
    session_regenerate_id(true);               // prevents session fixation
    $_SESSION['user_id'] = (int) $user['id'];
    unset($_SESSION['csrf_token']);            // fresh token for the new session

    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
        ->execute([$user['id']]);
    currentUser(true);
}

/** Fully log out: clear data, expire the cookie, destroy the session. */
function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Stop with an HTTP 403 page. */
function forbidden(string $message = 'You do not have permission to access this page.'): never
{
    http_response_code(403);
    exit('403 Forbidden: ' . e($message));
}

/** Visitors who are not logged in are sent to the login page. */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        flash('info', 'Please log in to continue.');
        redirect('/login.php');
    }
}

/** Only customers ('C') or only providers ('P'). Admins are not allowed here. */
function requireRole(string $role): void
{
    requireLogin();
    $user = currentUser();
    if ((int) $user['is_admin'] === 1 || $user['role'] !== $role) {
        forbidden();
    }
}

/** Only administrators. */
function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        forbidden();
    }
}

/** For login/signup pages: logged-in users are sent to their dashboard. */
function redirectIfLoggedIn(): void
{
    if ($user = currentUser()) {
        redirect(dashboardPath($user));
    }
}
