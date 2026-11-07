<?php
declare(strict_types=1);

/** Escape text for safe output in HTML (prevents XSS). Use on ALL user data. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Redirect to another page and stop. */
function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function isPost(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Previously submitted form value, escaped, for re-filling a form after an error. */
function old(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? e($value) : e($default);
}

/** Store a one-time message (type: success, error, info, warning) for the next page. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Fetch and clear all pending flash messages. */
function pullFlashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/** Output flash messages as HTML (styled later in our CSS). */
function renderFlashes(): void
{
    foreach (pullFlashes() as $f) {
        printf(
            '<div class="alert alert-%s" role="alert">%s</div>' . "\n",
            e($f['type']),
            e($f['message'])
        );
    }
}

/** Inline error text under a form field, if that field has an error. */
function fieldError(array $errors, string $field): string
{
    return isset($errors[$field])
        ? '<p class="field-error">' . e($errors[$field]) . '</p>'
        : '';
}

/** 1234.5 -> "Rs 1,234.50" */
function money(string|float|int $amount): string
{
    return 'Rs ' . number_format((float) $amount, 2);
}

/** MySQL datetime -> "05 Nov 2026, 3:30 PM" */
function formatDateTime(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    return date('d M Y, g:i A', strtotime($datetime));
}

/**
 * Turn submitted checkbox values into a clean list of integer IDs,
 * keeping only IDs that exist in $allowed.
 */
function cleanIdList(mixed $input, array $allowed): array
{
    if (!is_array($input)) {
        return [];
    }
    $ids = [];
    foreach ($input as $v) {
        if (is_string($v) && ctype_digit($v)) {
            $ids[] = (int) $v;
        }
    }
    return array_values(array_intersect(array_unique($ids), $allowed));
}

function notFound(): never
{
    http_response_code(404);
    exit('404 Not Found');
}
