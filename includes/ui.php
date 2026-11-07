<?php
declare(strict_types=1);

function badge(string $label, string $type): string
{
    return '<span class="badge badge-' . e($type) . '">' . e($label) . '</span>';
}

function verificationBadge(string $s): string
{
    return match ($s) {
        'A'     => badge('Approved', 'success'),
        'R'     => badge('Rejected', 'danger'),
        default => badge('Pending', 'warning'),
    };
}

function requestStatusBadge(string $s): string
{
    return match ($s) {
        'C'     => badge('Closed', 'muted'),
        'E'     => badge('Expired', 'danger'),
        default => badge('Open', 'success'),
    };
}

function offerStatusBadge(string $s): string
{
    return match ($s) {
        'A'     => badge('Accepted', 'success'),
        'R'     => badge('Rejected', 'danger'),
        'E'     => badge('Expired', 'muted'),
        default => badge('Pending', 'warning'),
    };
}

function bookingStatusBadge(string $s): string
{
    return match ($s) {
        'COMP'  => badge('Completed', 'success'),
        'CANC'  => badge('Cancelled', 'danger'),
        default => badge('Confirmed', 'info'),
    };
}

/** "Ayesha Khan", or the username if no name was given. */
function userDisplayName(array $u): string
{
    $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    return $name !== '' ? $name : (string) ($u['username'] ?? '');
}

/** Round profile picture, or a letter if the user has none. */
function avatar(array $u, string $class = 'avatar'): string
{
    if (!empty($u['profile_picture'])) {
        return '<img class="' . e($class) . '" src="' . e(uploadUrl($u['profile_picture'])) . '" alt="">';
    }
    $letter = mb_strtoupper(mb_substr(userDisplayName($u), 0, 1));
    return '<span class="' . e($class) . ' avatar-letter">' . e($letter) . '</span>';
}

/** Shorten text to one line for list cards. */
function snippet(string $text, int $len = 140): string
{
    return mb_strimwidth(preg_replace('/\s+/', ' ', $text), 0, $len, '…');
}

/** ★★★★☆ 4.0 (3) */
function ratingStars(?string $avg, int $count): string
{
    if ($avg === null || $count === 0) {
        return '<span class="muted">No reviews yet</span>';
    }
    $full = max(0, min(5, (int) round((float) $avg)));
    return '<span class="stars">' . str_repeat('★', $full) . str_repeat('☆', 5 - $full)
         . '</span> <small>' . e($avg) . ' (' . $count . ')</small>';
}

/** Thumbnail row for stored image paths. */
function gallery(array $paths): string
{
    if (!$paths) {
        return '';
    }
    $html = '<div class="gallery">';
    foreach ($paths as $p) {
        $u = e(uploadUrl((string) $p));
        $html .= '<a href="' . $u . '" target="_blank" rel="noopener"><img src="' . $u . '" alt="Uploaded image" loading="lazy"></a>';
    }
    return $html . '</div>';
}

/** Small inline SVG icons (stroke follows the text colour). */
function icon(string $name, int $size = 18): string
{
    static $paths = [
        'pin'    => '<path d="M12 21s-7-6.1-7-11a7 7 0 1 1 14 0c0 4.9-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18"/><circle cx="16.5" cy="14.5" r="1"/>',
        'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'camera' => '<path d="M4 8h3l1.5-2h7L17 8h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>',
        'mail'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
        'phone'  => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A15 15 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
        'truck'  => '<path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="17.5" r="1.7"/><circle cx="17" cy="17.5" r="1.7"/>',
        'check'  => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** FixMate logo mark: a job ticket with a wrench. */
function logoMark(int $height = 36): string
{
    $w = (int) round($height * 44 / 40);
    return '<svg class="brand-logo" width="' . $w . '" height="' . $height . '" viewBox="0 0 44 40" aria-hidden="true">'
         . '<path fill="#2442F5" d="M10 3H34a8 8 0 0 1 8 8V15.5a4.5 4.5 0 0 0 0 9V29a8 8 0 0 1-8 8H10a8 8 0 0 1-8-8V24.5a4.5 4.5 0 0 0 0-9V11a8 8 0 0 1 8-8Z"/>'
         . '<line x1="13" y1="27" x2="23" y2="17" stroke="#fff" stroke-width="5" stroke-linecap="round"/>'
         . '<circle cx="27" cy="14" r="6.5" fill="#fff"/>'
         . '<rect x="27" y="11.5" width="12" height="5" fill="#2442F5" transform="rotate(-45 27 14)"/>'
         . '<circle cx="13" cy="27" r="1.5" fill="#FFD23F"/></svg>';
}
