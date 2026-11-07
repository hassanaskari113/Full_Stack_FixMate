<?php
declare(strict_types=1);

/** Get (or create) this session's CSRF token. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input to place inside every POST form. */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/** Call at the top of any code that handles a POST. Stops the request if the token is wrong. */
function verifyCsrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrfToken(), $sent)) {
        http_response_code(403);
        exit('Security check failed. Please go back, refresh the page and try again.');
    }
}
