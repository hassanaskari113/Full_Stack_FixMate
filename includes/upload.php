<?php
declare(strict_types=1);

const IMAGE_MIME_EXT = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];
const UPLOAD_SUBDIRS = ['users', 'request_images', 'review_images'];

function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The image is too large.',
        UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE   => 'No file was selected.',
        default              => 'The image could not be uploaded.',
    };
}

/** Returns an error message, or null if the uploaded image is acceptable. */
function validateImageUpload(array $file, int $maxBytes = 2 * 1024 * 1024): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return uploadErrorMessage((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE));
    }
    if ($file['size'] > $maxBytes) {
        return 'Each image must be ' . round($maxBytes / 1048576) . ' MB or smaller.';
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return 'Invalid upload.';
    }
    // Check the REAL content type (not the browser-supplied one or the extension).
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(IMAGE_MIME_EXT[$mime])) {
        return 'Only JPG, PNG or WebP images are allowed.';
    }
    if (@getimagesize($file['tmp_name']) === false) {
        return 'The file is not a valid image.';
    }
    return null;
}

/**
 * Move a validated upload into public/uploads/<subdir>/ under a random name.
 * Returns the relative path to store in the database, e.g. "users/ab12....jpg".
 */
function storeImage(array $file, string $subdir): string
{
    if (!in_array($subdir, UPLOAD_SUBDIRS, true)) {
        throw new InvalidArgumentException('Invalid upload folder.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext  = IMAGE_MIME_EXT[$mime];                       // extension comes from content, never from the user
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = BASE_PATH . '/public/uploads/' . $subdir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }
    chmod($dest, 0644);
    return $subdir . '/' . $name;
}

/** Delete a stored upload (path as saved in the database). */
function deleteUploadedFile(string $relative): void
{
    if ($relative === '') {
        return;
    }
    $subdir = dirname($relative);
    if (!in_array($subdir, UPLOAD_SUBDIRS, true)) {
        return;
    }
    $path = BASE_PATH . '/public/uploads/' . $subdir . '/' . basename($relative);
    if (is_file($path)) {
        @unlink($path);
    }
}

function uploadUrl(string $relative): string
{
    return '/uploads/' . $relative;
}

/** Turn a multi-file <input name="images[]"> into a list of single-file arrays (empty slots skipped). */
function normalizeMultiFiles(?array $files): array
{
    $out = [];
    if (!$files || !is_array($files['name'] ?? null)) {
        return $out;
    }
    foreach ($files['name'] as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = [
            'name' => $name, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i], 'size' => $files['size'][$i],
        ];
    }
    return $out;
}

/** Validate a list of images. Returns the first error message, or null. */
function validateImageList(array $files, int $max = 5): ?string
{
    if (count($files) > $max) {
        return "You can upload at most $max images.";
    }
    foreach ($files as $i => $f) {
        if ($msg = validateImageUpload($f)) {
            return 'Image ' . ($i + 1) . ': ' . $msg;
        }
    }
    return null;
}
