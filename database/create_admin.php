<?php
// Create an administrator account. Run from the terminal ONLY:
//   php /var/www/fixmate/database/create_admin.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/bootstrap.php';

function ask(string $label, bool $hidden = false): string
{
    echo $label . ': ';
    if ($hidden) { shell_exec('stty -echo'); }
    $value = trim((string) fgets(STDIN));
    if ($hidden) { shell_exec('stty echo'); echo "\n"; }
    return $value;
}

$email    = strtolower(ask('Admin email'));
$username = ask('Admin username');
$first    = ask('First name');
$password = ask('Password (min 8 chars)', true);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)
    || !preg_match('/^[A-Za-z0-9_.]{3,30}$/', $username)
    || $first === '' || strlen($password) < 8 || strlen($password) > 72) {
    exit("Invalid input. Nothing was created.\n");
}

try {
    db()->prepare(
        'INSERT INTO users (username, first_name, last_name, email, password_hash, role, is_admin)
         VALUES (?, ?, ?, ?, ?, ?, 1)'
    )->execute([$username, $first, 'Admin', $email, password_hash($password, PASSWORD_DEFAULT), 'C']);
    echo "Admin account created for $email\n";
} catch (PDOException $e) {
    echo ($e->getCode() === '23000')
        ? "That email or username already exists.\n"
        : "Database error: " . $e->getMessage() . "\n";
}
