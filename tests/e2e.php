<?php
declare(strict_types=1);

/**
 * FixMate end-to-end test (command line only).
 *
 *   php tests/e2e.php [--base=http://fixmate.local] [--keep]
 *
 * It drives the REAL running site over HTTP (sessions, CSRF, uploads, redirects) and
 * checks the database after each step. Everything it creates is prefixed "zz_test_" /
 * "ZZ" and is removed again at the end (use --keep to leave it for debugging).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/e2e_lib.php';

const PW = 'Test@12345';

$opts = getopt('', ['base::', 'keep', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php tests/e2e.php [--base=http://fixmate.local] [--keep]\n";
    echo "  --keep   leave the generated test data in place (for debugging)\n";
    exit(0);
}
T::$base  = rtrim(is_string($opts['base'] ?? null) ? $opts['base'] : 'http://fixmate.local', '/');
T::$root  = dirname(__DIR__);
T::$start = microtime(true);
T::$run   = bin2hex(random_bytes(3));
$KEEP     = isset($opts['keep']);
$run      = T::$run;

// ---------------------------------------------------------------- database connection

try {
    $appCfg = require T::$root . '/config/app.php';
    $dbCfg  = require T::$root . '/config/database.php';
    date_default_timezone_set($appCfg['timezone']);
    T::$pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbCfg['host'], $dbCfg['name'], $dbCfg['charset']),
        $dbCfg['user'],
        $dbCfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    T::$pdo->exec("SET time_zone = '" . $appCfg['db_timezone'] . "'");
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot connect to the database: ' . $e->getMessage() . "\n");
    exit(2);
}

// ---------------------------------------------------------------- helpers specific to this flow

function acct(string $key): array
{
    $run = T::$run;
    return ['email' => "zz_test_{$run}_{$key}@example.test", 'username' => "zzt{$run}{$key}"];
}

function uid(string $key): int
{
    return (int) sql_val('SELECT id FROM users WHERE email = ?', [acct($key)['email']]);
}

function pid(string $key): int
{
    return (int) sql_val(
        'SELECT pp.id FROM provider_profiles pp JOIN users u ON u.id = pp.user_id WHERE u.email = ?',
        [acct($key)['email']]
    );
}

function dtLocal(string $relative): string
{
    return date('Y-m-d\TH:i', strtotime($relative));
}

function signup(Client $c, string $key, string $role, array $extra = []): array
{
    $a = acct($key);
    return $c->post('/signup.php', array_merge([
        'role' => $role, 'first_name' => ucfirst($key), 'last_name' => 'Tester',
        'username' => $a['username'], 'email' => $a['email'], 'phone_number' => '',
        'password' => PW, 'password_confirm' => PW,
    ], $extra));
}

function newRequest(Client $c, int $catId, string $desc, string $loc, array $images = [], array $over = []): array
{
    $fields = array_merge([
        'category_id' => $catId, 'description' => $desc, 'min_budget' => '500', 'max_budget' => '2000',
        'urgency' => dtLocal('+2 days'), 'location' => $loc,
    ], $over);
    if ($images) {
        $fields['images'] = $images;
    }
    return $c->post('/customer/request_new.php', $fields);
}

function reqId(string $token): int
{
    return (int) sql_val('SELECT id FROM service_requests WHERE description LIKE ?', ['%' . $token . '%']);
}

function makeRequest(Client $c, string $tag, int $catId, string $loc, array $images = []): int
{
    $token = 'ZZTEST-' . T::$run . '-' . $tag;
    newRequest($c, $catId, $token . ' request created by the automated test', $loc, $images);
    return reqId($token);
}

function sendOffer(Client $p, int $rid, string $price, string $arrival = '+3 days'): array
{
    return $p->post('/provider/request.php?id=' . $rid, ['price' => $price, 'arrival_time' => dtLocal($arrival)]);
}

function offerId(int $rid, string $providerKey): int
{
    return (int) sql_val(
        'SELECT id FROM offers WHERE service_request_id = ? AND service_provider_id = ?',
        [$rid, pid($providerKey)]
    );
}

function status(string $table, int $id): ?string
{
    if (!in_array($table, ['offers', 'service_requests', 'bookings', 'provider_profiles'], true)) {
        throw new InvalidArgumentException('bad table');
    }
    $col = $table === 'provider_profiles' ? 'verification_status' : 'status';
    $v = sql_val("SELECT $col FROM $table WHERE id = ?", [$id]);
    return $v === null ? null : (string) $v;
}

function pageHas(Client $c, string $path, string $needle): bool
{
    return has($c->get($path)['body'], $needle);
}

/** Remove everything the test created (silent). Also deletes upload files left behind by this run. */
function finalCleanup(): void
{
    $run = T::$run;
    try {
        sql_exec('DELETE FROM users WHERE email LIKE ?', ["zz_test_{$run}_%"]);
        sql_exec('DELETE FROM service_categories WHERE name LIKE ?', ["ZZ Test Cat {$run}%"]);
        sql_exec('DELETE FROM service_areas WHERE name LIKE ?', ["ZZArea{$run}%"]);
    } catch (Throwable $e) {
        echo color('y', 'Cleanup warning: ' . $e->getMessage()) . "\n";
    }
    foreach (['users', 'request_images', 'review_images'] as $sub) {
        foreach (glob(T::$root . "/public/uploads/$sub/*") ?: [] as $f) {
            if (!is_file($f) || basename($f) === '.gitkeep' || filemtime($f) < T::$start - 2) {
                continue;
            }
            $rel = $sub . '/' . basename($f);
            $used = (int) sql_val('SELECT (SELECT COUNT(*) FROM users WHERE profile_picture = ?)
                                        + (SELECT COUNT(*) FROM request_images WHERE image = ?)
                                        + (SELECT COUNT(*) FROM review_images WHERE image = ?)', [$rel, $rel, $rel]);
            if ($used === 0) {
                @unlink($f);
            }
        }
    }
    @unlink(T::$root . '/public/uploads/users/zz_exec_' . $run . '.php');
    foreach (T::$tmpFiles as $f) {
        @unlink($f);
    }
}

// ---------------------------------------------------------------- banner

echo color('b', "FixMate end-to-end test") . "\n";
echo "Site: " . T::$base . "   Run id: $run\n";
echo "Creates users 'zz_test_{$run}_*@example.test' and removes them when finished.\n";

// ================================================================ the tests

try {

    // ------------------------------------------------------------ 0
    section('0. Preflight');
    $guest = new Client('guest');
    $r = $guest->get('/');
    check('Site is reachable at ' . T::$base, $r['status'] === 200 && has($r['body'], 'FixMate'), 'HTTP ' . $r['status']);
    $tables = (int) sql_val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
    check('Database contains the 13 expected tables', $tables === 13, "found $tables");

    $adminAcct = acct('admin');
    sql_exec(
        'INSERT INTO users (username, first_name, last_name, email, password_hash, role, is_admin) VALUES (?, ?, ?, ?, ?, ?, 1)',
        [$adminAcct['username'], 'Test', 'Admin', $adminAcct['email'], password_hash(PW, PASSWORD_DEFAULT), 'C']
    );

    // ------------------------------------------------------------ 1
    section('1. Public pages and guest restrictions');
    foreach ([
        '/' => 'Post the job', '/services.php' => 'Every service', '/about.php' => 'marketplace',
        '/login.php' => 'Welcome back', '/signup.php' => 'Create your account',
    ] as $path => $needle) {
        $r = $guest->get($path);
        check("GET $path loads", $r['status'] === 200 && has($r['body'], $needle), 'HTTP ' . $r['status']);
    }
    foreach ([
        '/customer/dashboard.php', '/provider/dashboard.php', '/admin/dashboard.php', '/admin/users.php',
        '/customer/request_new.php', '/provider/requests.php', '/bookings.php', '/booking.php?id=1', '/profile.php',
    ] as $path) {
        $r = $guest->get($path);
        check("Guest is redirected to login from $path", $r['first'] === 302 && str_contains($r['location'], '/login.php'), 'HTTP ' . $r['first'] . ' ' . $r['location']);
    }
    $r = $guest->get('/no-such-page-' . $run . '.php');
    check('Unknown URL returns 404', $r['status'] === 404);

    $probe = new Client('cookie-probe');
    $probe->get('/login.php');
    check('Session cookie is HttpOnly', stripos($probe->lastHeaders, 'HttpOnly') !== false);
    check('Session cookie is SameSite=Lax', stripos($probe->lastHeaders, 'SameSite=Lax') !== false);

    // ------------------------------------------------------------ 2
    section('2. Sign up, login, logout and sessions');
    $r = $guest->post('/signup.php', [
        'role' => 'C', 'first_name' => '', 'last_name' => '', 'username' => 'a!', 'email' => 'not-an-email',
        'phone_number' => '12', 'password' => 'short', 'password_confirm' => 'different',
    ]);
    check('Signup shows errors for first name, username, email, phone and short password',
        $r['status'] === 200 && has($r['body'], 'Enter your first name') && has($r['body'], '3-30 characters')
        && has($r['body'], 'valid email') && has($r['body'], '10-15 digits') && has($r['body'], 'at least 8 characters'));

    $a = acct('mismatch');
    $r = signup($guest, 'mismatch', 'C', ['password' => 'LongEnough1', 'password_confirm' => 'Different22']);
    check('Signup rejects mismatched passwords', has($r['body'], 'Passwords do not match') && !sql_val('SELECT id FROM users WHERE email = ?', [$a['email']]));

    $r = signup($guest, 'badrole', 'A');
    check('Signup rejects an invalid role', has($r['body'], 'Please choose Customer or Provider'));

    $a = acct('fakepic');
    $r = signup($guest, 'fakepic', 'C', ['profile_picture' => fakeImg()]);
    check('Signup rejects a text file disguised as .jpg (real content-type check)',
        has($r['body'], 'Only JPG, PNG or WebP') && !sql_val('SELECT id FROM users WHERE email = ?', [$a['email']]));

    $cust1 = new Client('cust1');
    $phone1 = '+9230' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    $r = signup($cust1, 'cust1', 'C', ['phone_number' => $phone1, 'profile_picture' => img('png', 1)]);
    check('Customer signup lands on the customer dashboard with a welcome message',
        $r['url'] === '/customer/dashboard.php' && has($r['body'], 'Welcome to FixMate'), 'ended at ' . $r['url']);
    $u = sql_row('SELECT * FROM users WHERE email = ?', [acct('cust1')['email']]);
    check('Customer stored with role C and no admin rights', $u !== null && $u['role'] === 'C' && (int) $u['is_admin'] === 0);
    check('Password is stored as a bcrypt hash, never plaintext',
        $u !== null && $u['password_hash'] !== PW && str_starts_with($u['password_hash'], '$2y$') && password_verify(PW, $u['password_hash']));
    check('Phone number saved', $u !== null && $u['phone_number'] === $phone1);
    check('Profile picture saved on disk', $u !== null && $u['profile_picture'] !== '' && uploadExists($u['profile_picture']));

    $r = signup($guest, 'dupemail', 'C', ['email' => acct('cust1')['email']]);
    check('Duplicate email is rejected', has($r['body'], 'already exists'));
    $r = signup($guest, 'dupuser', 'C', ['username' => strtoupper(acct('cust1')['username'])]);
    check('Duplicate username is rejected (case-insensitive)', has($r['body'], 'already taken'));
    $r = signup($guest, 'dupphone', 'C', ['phone_number' => $phone1]);
    check('Duplicate phone number is rejected', has($r['body'], 'already registered'));

    $cust2 = new Client('cust2');
    $r = signup($cust2, 'cust2', 'C');
    check('Second customer signs up', $r['url'] === '/customer/dashboard.php');
    check('Empty phone number is stored as NULL, not an empty string',
        (int) sql_val('SELECT phone_number IS NULL FROM users WHERE email = ?', [acct('cust2')['email']]) === 1);

    $prov = [];
    foreach (['prov1', 'prov2', 'prov3'] as $k) {
        $prov[$k] = new Client($k);
        $r = signup($prov[$k], $k, 'P');
        check("Provider $k signs up and lands on the provider dashboard", $r['url'] === '/provider/dashboard.php', 'ended at ' . $r['url']);
    }
    [$prov1, $prov2, $prov3] = [$prov['prov1'], $prov['prov2'], $prov['prov3']];

    $mass = new Client('mass');
    signup($mass, 'mass', 'C', ['is_admin' => '1']);
    check('Mass-assignment: posting is_admin=1 during signup does nothing',
        (int) sql_val('SELECT is_admin FROM users WHERE email = ?', [acct('mass')['email']]) === 0);

    $t = new Client('login-probe');
    $r = $t->post('/login.php', ['email' => acct('cust2')['email'], 'password' => 'wrong-password']);
    check('Wrong password gives a generic error', has($r['body'], 'Invalid email or password.'));
    $r = $t->post('/login.php', ['email' => "nobody_{$run}@example.test", 'password' => PW]);
    check('Unknown email gives the same generic error (no account enumeration)', has($r['body'], 'Invalid email or password.'));
    $r = $t->post('/login.php', ['email' => "' OR '1'='1", 'password' => "' OR '1'='1"]);
    check('SQL-injection style login is just an invalid login', has($r['body'], 'Invalid email or password.') && $r['url'] === '/login.php');

    $fix = new Client('session-fix');
    $fix->get('/login.php');
    $before = $fix->sessionId();
    $r = $fix->post('/login.php', ['email' => acct('cust2')['email'], 'password' => PW]);
    $after = $fix->sessionId();
    check('Login works and lands on the customer dashboard', $r['url'] === '/customer/dashboard.php' && has($r['body'], 'Welcome back'));
    check('Session ID changes at login (prevents session fixation)', $before !== '' && $after !== '' && $before !== $after);
    $fix->get('/logout.php');
    check('GET /logout.php does not log you out', $fix->get('/customer/dashboard.php')['status'] === 200);
    check('Logout without a CSRF token is blocked', $fix->request('POST', '/logout.php')['status'] === 403);
    $r = $fix->post('/logout.php');
    check('Logout works via POST with a CSRF token', has($r['body'], 'logged out') && $r['url'] === '/login.php');
    check('Dashboard is closed after logout', $fix->get('/customer/dashboard.php')['first'] === 302);

    $r = $cust1->request('POST', '/profile.php', ['form' => 'profile', 'first_name' => 'X']);
    check('A form posted without a CSRF token is rejected (403)', $r['status'] === 403 && has($r['body'], 'Security check failed'));

    check('Customer is blocked from the provider area (403)', $cust1->get('/provider/dashboard.php')['status'] === 403);
    check('Customer is blocked from the admin area (403)', $cust1->get('/admin/dashboard.php')['status'] === 403);
    check('Provider is blocked from the customer area (403)', $prov1->get('/customer/dashboard.php')['status'] === 403);
    check('Provider is blocked from the admin area (403)', $prov1->get('/admin/users.php')['status'] === 403);

    $admin = new Client('admin');
    $r = $admin->post('/login.php', ['email' => $adminAcct['email'], 'password' => PW]);
    check('Admin logs in and lands on the admin dashboard', $r['url'] === '/admin/dashboard.php' && has($r['body'], 'Admin dashboard'), 'ended at ' . $r['url']);
    check('Admin is kept out of customer pages (403)', $admin->get('/customer/dashboard.php')['status'] === 403);

    // ------------------------------------------------------------ 3
    section('3. Profile settings');
    $c1 = acct('cust1');
    $r = $cust1->get('/profile.php');
    check('Profile page loads', $r['status'] === 200 && has($r['body'], 'Profile settings'));
    $r = $cust1->post('/profile.php', ['form' => 'profile', 'first_name' => '<i>Zed</i>', 'last_name' => 'Tester', 'username' => $c1['username'], 'phone_number' => $phone1]);
    check('Profile details can be updated', has($r['body'], 'Profile updated.'));
    $r = $cust1->get('/profile.php');
    check('HTML in a name is escaped on output (XSS)', str_contains($r['body'], '&lt;i&gt;Zed') && !str_contains($r['body'], '<i>Zed</i>'));
    $cust1->post('/profile.php', ['form' => 'profile', 'first_name' => 'Zed', 'last_name' => 'Tester', 'username' => $c1['username'], 'phone_number' => $phone1]);

    $r = $cust1->post('/profile.php', ['form' => 'profile', 'first_name' => 'Zed', 'last_name' => 'Tester', 'username' => 'a!', 'phone_number' => '']);
    check('Profile rejects an invalid username', has($r['body'], '3-30 characters'));
    $r = $cust1->post('/profile.php', ['form' => 'profile', 'first_name' => 'Zed', 'last_name' => 'Tester', 'username' => acct('cust2')['username'], 'phone_number' => $phone1]);
    check('Profile rejects a username already taken by someone else', has($r['body'], 'already taken'));

    $oldPic = (string) sql_val('SELECT profile_picture FROM users WHERE id = ?', [uid('cust1')]);
    $cust1->post('/profile.php', ['form' => 'profile', 'first_name' => 'Zed', 'last_name' => 'Tester', 'username' => $c1['username'], 'phone_number' => $phone1, 'profile_picture' => img('jpg', 2)]);
    $newPic = (string) sql_val('SELECT profile_picture FROM users WHERE id = ?', [uid('cust1')]);
    check('Replacing the picture stores the new file and deletes the old one',
        $newPic !== '' && $newPic !== $oldPic && uploadExists($newPic) && !uploadExists($oldPic));
    $cust1->post('/profile.php', ['form' => 'profile', 'first_name' => 'Zed', 'last_name' => 'Tester', 'username' => $c1['username'], 'phone_number' => $phone1, 'remove_picture' => '1']);
    check('Removing the picture clears it and deletes the file',
        (string) sql_val('SELECT profile_picture FROM users WHERE id = ?', [uid('cust1')]) === '' && !uploadExists($newPic));
    $cust1->post('/profile.php', ['form' => 'profile', 'first_name' => 'Zed', 'last_name' => 'Tester', 'username' => $c1['username'], 'phone_number' => $phone1, 'profile_picture' => img('png', 3)]);

    $cust1Pw = 'NewPass@999';
    $r = $cust1->post('/profile.php', ['form' => 'password', 'current_password' => 'not-my-password', 'new_password' => $cust1Pw, 'new_password_confirm' => $cust1Pw]);
    check('Password change needs the correct current password', has($r['body'], 'current password is incorrect'));
    $r = $cust1->post('/profile.php', ['form' => 'password', 'current_password' => PW, 'new_password' => $cust1Pw, 'new_password_confirm' => 'Other@12345']);
    check('Password change needs matching confirmation', has($r['body'], 'do not match'));
    $r = $cust1->post('/profile.php', ['form' => 'password', 'current_password' => PW, 'new_password' => $cust1Pw, 'new_password_confirm' => $cust1Pw]);
    check('Password can be changed', has($r['body'], 'Password changed.'));
    $t = new Client('pw-old');
    check('Old password no longer works', has($t->post('/login.php', ['email' => $c1['email'], 'password' => PW])['body'], 'Invalid email or password.'));
    $t = new Client('pw-new');
    check('New password works', $t->post('/login.php', ['email' => $c1['email'], 'password' => $cust1Pw])['url'] === '/customer/dashboard.php');

    // ------------------------------------------------------------ 4
    section('4. Admin: categories, services and service areas');
    $catName = "ZZ Test Cat $run";
    $catName2 = "ZZ Test Cat $run B";
    $svcName = "ZZ Svc $run";
    $areaA = "ZZArea{$run}A";
    $areaB = "ZZArea{$run}B";

    $r = $admin->post('/admin/categories.php', ['action' => 'save', 'id' => 0, 'name' => $catName]);
    check('Admin adds a category', has($r['body'], 'Category added.'));
    $catId = (int) sql_val('SELECT id FROM service_categories WHERE name = ?', [$catName]);
    $r = $admin->post('/admin/categories.php', ['action' => 'save', 'id' => 0, 'name' => strtolower($catName)]);
    check('Duplicate category name is rejected (case-insensitive)', has($r['body'], 'already exists'));
    $r = $admin->post('/admin/categories.php', ['action' => 'save', 'id' => 0, 'name' => 'a']);
    check('Too-short category name is rejected', has($r['body'], 'between 2 and 50'));
    $r = $admin->post('/admin/categories.php', ['action' => 'save', 'id' => $catId, 'name' => $catName2]);
    check('Admin renames a category', has($r['body'], 'Category updated.') && sql_val('SELECT name FROM service_categories WHERE id = ?', [$catId]) === $catName2);

    $r = $admin->post('/admin/services.php', ['action' => 'save', 'id' => 0, 'name' => $svcName, 'category_id' => $catId]);
    check('Admin adds a service', has($r['body'], 'Service added.'));
    $svcId = (int) sql_val('SELECT id FROM services WHERE name = ?', [$svcName]);
    $r = $admin->post('/admin/services.php', ['action' => 'save', 'id' => 0, 'name' => $svcName, 'category_id' => $catId]);
    check('Duplicate service name is rejected', has($r['body'], 'already exists'));
    $r = $admin->post('/admin/services.php', ['action' => 'save', 'id' => 0, 'name' => "ZZ Svc $run 2", 'category_id' => 0]);
    check('A service must belong to a category', has($r['body'], 'Choose a category'));

    foreach ([$areaA, $areaB] as $nm) {
        $r = $admin->post('/admin/areas.php', ['action' => 'save', 'id' => 0, 'name' => $nm]);
        check("Admin adds service area $nm", has($r['body'], 'Service area added.'));
    }
    $areaAId = (int) sql_val('SELECT id FROM service_areas WHERE name = ?', [$areaA]);
    $areaBId = (int) sql_val('SELECT id FROM service_areas WHERE name = ?', [$areaB]);
    $r = $admin->post('/admin/areas.php', ['action' => 'save', 'id' => 0, 'name' => $areaA]);
    check('Duplicate service area is rejected', has($r['body'], 'already exists'));

    $r = $cust1->post('/admin/categories.php', ['action' => 'delete', 'id' => $catId]);
    check('A customer cannot delete categories (403) and nothing is deleted', $r['status'] === 403 && sql_val('SELECT id FROM service_categories WHERE id = ?', [$catId]) !== null);
    $r = $admin->request('POST', '/admin/categories.php', ['action' => 'delete', 'id' => $catId]);
    check('Admin POST without a CSRF token is rejected (403) and nothing is deleted', $r['status'] === 403 && sql_val('SELECT id FROM service_categories WHERE id = ?', [$catId]) !== null);
    $r = $guest->get('/services.php');
    check('Public Services page lists the new category and service', has($r['body'], $catName2) && has($r['body'], $svcName));

    // ------------------------------------------------------------ 5
    section('5. Provider profiles and verification');
    $r = $prov1->get('/provider/requests.php');
    check('A provider with no profile is sent to create one', $r['first'] === 302 && str_contains($r['location'], '/provider/profile.php'));
    $r = $prov1->post('/provider/profile.php', ['experience' => 'abc', 'bio' => '']);
    check('Profile form validates experience, services and areas',
        has($r['body'], 'whole years') && has($r['body'], 'at least one service') && has($r['body'], 'at least one area'));

    $profileFields = fn(array $areas, string $years = '5') => ['experience' => $years, 'bio' => 'Automated test provider', 'services' => [$svcId], 'areas' => $areas];
    $r = $prov1->post('/provider/profile.php', $profileFields([$areaAId]));
    check('Provider submits a profile for verification', has($r['body'], 'Profile submitted'));
    $prov1Profile = pid('prov1');
    check('New profile starts Pending', status('provider_profiles', $prov1Profile) === 'P');
    check('Service and area selections are saved (many-to-many)',
        (int) sql_val('SELECT COUNT(*) FROM provider_profile_services WHERE provider_profile_id = ?', [$prov1Profile]) === 1
        && (int) sql_val('SELECT COUNT(*) FROM provider_profile_service_areas WHERE provider_profile_id = ?', [$prov1Profile]) === 1);
    $prov2->post('/provider/profile.php', $profileFields([$areaBId], '3'));
    $prov3->post('/provider/profile.php', $profileFields([$areaAId], '2'));

    $r = $prov1->get('/provider/requests.php');
    check('Unapproved provider cannot open the matching-requests list',
        $r['first'] === 302 && str_contains($r['location'], '/provider/dashboard.php') && has($r['body'], 'approved by an administrator'));
    check('Provider dashboard shows the pending verification notice', pageHas($prov1, '/provider/dashboard.php', 'Verification pending'));

    check('Admin sees the pending providers', pageHas($admin, '/admin/providers.php?status=P', acct('prov1')['email']));
    $r = $prov3->post('/admin/providers.php', ['action' => 'approve', 'id' => pid('prov3'), 'filter' => 'P']);
    check('A provider cannot approve themselves (403)', $r['status'] === 403 && status('provider_profiles', pid('prov3')) === 'P');
    $r = $admin->post('/admin/providers.php', ['action' => 'approve', 'id' => $prov1Profile, 'filter' => 'P']);
    check('Admin approves provider 1', has($r['body'], 'Provider approved.') && status('provider_profiles', $prov1Profile) === 'A');
    $r = $admin->post('/admin/providers.php', ['action' => 'approve', 'id' => pid('prov2'), 'filter' => 'P']);
    check('Admin approves provider 2', status('provider_profiles', pid('prov2')) === 'A');
    $r = $admin->post('/admin/providers.php', ['action' => 'reject', 'id' => pid('prov3'), 'filter' => 'P']);
    check('Admin rejects provider 3', has($r['body'], 'Provider rejected.') && status('provider_profiles', pid('prov3')) === 'R');
    check('Rejected provider sees the rejection notice', pageHas($prov3, '/provider/dashboard.php', 'was rejected'));
    $r = $prov3->post('/provider/profile.php', $profileFields([$areaAId], '2'));
    check('A rejected provider resubmits by saving the profile (back to Pending)', has($r['body'], 'resubmitted') && status('provider_profiles', pid('prov3')) === 'P');

    // ------------------------------------------------------------ 6
    section('6. Customer requests, images and provider matching');
    $locA = "House 5, $areaA, Lahore";

    $r = newRequest($cust1, 0, 'short', 'x', [], ['min_budget' => '500', 'max_budget' => '100', 'urgency' => dtLocal('-1 day')]);
    check('Request form validates category, description, budget, deadline and location',
        has($r['body'], 'Choose a category') && has($r['body'], 'Describe the job') && has($r['body'], 'Maximum must not be less')
        && has($r['body'], 'must be in the future') && has($r['body'], 'Enter your address'));
    $r = newRequest($cust1, $catId, 'A description that is long enough', $locA, [], ['min_budget' => '0']);
    check('Budget of zero is rejected', has($r['body'], 'Enter an amount above 0'));
    $r = newRequest($cust1, $catId, 'A description that is long enough', $locA, [img('png', 1), img('png', 2), img('png', 3), img('png', 4), img('png', 5), img('png', 6)]);
    check('More than 5 images is rejected', has($r['body'], 'at most 5 images'));
    $r = newRequest($cust1, $catId, 'A description that is long enough', $locA, [fakeImg()]);
    check('A fake image is rejected on a request', has($r['body'], 'Only JPG, PNG or WebP'));
    check('None of the invalid submissions created a request', (int) sql_val('SELECT COUNT(*) FROM service_requests WHERE customer_id = ?', [uid('cust1')]) === 0);

    $descR1 = "ZZTEST-$run-R1 kitchen tap <em>leaking</em> badly";
    $r = newRequest($cust1, $catId, $descR1, $locA, [img('png', 3), img('jpg', 4), img('png', 5)]);
    $r1 = reqId("ZZTEST-$run-R1");
    check('Request is created with 3 images and the customer lands on it',
        $r1 > 0 && preg_match('#^/customer/request\.php\?id=\d+$#', $r['url']) === 1 && has($r['body'], 'Your request is live'));
    check('New request starts Open', status('service_requests', $r1) === 'O');
    $r1Imgs = array_column(sql_rows('SELECT image FROM request_images WHERE request_id = ?', [$r1]), 'image');
    check('3 image rows saved and all 3 files are on disk', count($r1Imgs) === 3 && allFilesExist($r1Imgs));
    $r = $cust1->get('/customer/request_new.php?category=' . $catId);
    check('Opening the form with ?category= preselects that category', str_contains($r['body'], 'value="' . $catId . '" selected'));
    check('Customer sees the request in My requests', pageHas($cust1, '/customer/requests.php', "ZZTEST-$run-R1"));
    check('The Open tab lists it and the Closed tab does not',
        pageHas($cust1, '/customer/requests.php?status=O', "ZZTEST-$run-R1") && !pageHas($cust1, '/customer/requests.php?status=C', "ZZTEST-$run-R1"));

    check('Approved provider in the right area sees the request', pageHas($prov1, '/provider/requests.php', "ZZTEST-$run-R1"));
    check('Approved provider in a DIFFERENT area does not see it', !pageHas($prov2, '/provider/requests.php', "ZZTEST-$run-R1"));
    check('Unapproved provider cannot list requests', $prov3->get('/provider/requests.php')['first'] === 302);
    check('Provider outside the area cannot open the request (404)', $prov2->get("/provider/request.php?id=$r1")['status'] === 404);
    $r = $prov1->get("/provider/request.php?id=$r1");
    preg_match_all('#/uploads/(request_images/[a-f0-9]+\.\w+)#', $r['body'], $mm);
    check('Matching provider sees the details and all 3 photos', $r['status'] === 200 && count(array_unique($mm[1])) === 3);
    check('Request text is escaped on the provider page (XSS)', str_contains($r['body'], '&lt;em&gt;leaking') && !str_contains($r['body'], '<em>leaking</em>'));
    check('Another customer cannot open the request (404)', $cust2->get("/customer/request.php?id=$r1")['status'] === 404);
    check('Another customer does not see it in their list', !pageHas($cust2, '/customer/requests.php', "ZZTEST-$run-R1"));
    check('A nonexistent request id returns 404', $cust1->get('/customer/request.php?id=999999999')['status'] === 404);
    $guest->get('/');
    $r = $guest->get('/uploads/' . $r1Imgs[0]);
    check('Uploaded images are served as images', $r['status'] === 200 && stripos($guest->lastHeaders, 'image/') !== false);

    $r = $prov2->post('/provider/profile.php', $profileFields([$areaAId, $areaBId], '3'));
    check('Approved provider can edit their areas', has($r['body'], 'Profile updated.'));
    check('Editing keeps the provider Approved', status('provider_profiles', pid('prov2')) === 'A');
    check('After adding the area, provider 2 now matches the request', pageHas($prov2, '/provider/requests.php', "ZZTEST-$run-R1"));

    // ------------------------------------------------------------ 7
    section('7. Offers');
    $u1 = "/provider/request.php?id=$r1";
    sendOffer($prov3, $r1, '1000');
    check('An unapproved provider cannot submit an offer', (int) sql_val('SELECT COUNT(*) FROM offers WHERE service_provider_id = ?', [pid('prov3')]) === 0);

    check('Offer price 0 is rejected', has(sendOffer($prov1, $r1, '0')['body'], 'above 0'));
    check('Negative offer price is rejected', has(sendOffer($prov1, $r1, '-5')['body'], 'above 0'));
    check('Offer price that does not fit DECIMAL(7,2) is rejected', has(sendOffer($prov1, $r1, '123456')['body'], 'above 0'));
    check('Arrival time in the past is rejected', has(sendOffer($prov1, $r1, '1500', '-1 day')['body'], 'must be in the future'));
    $r = sendOffer($prov1, $r1, '1500');
    check('Approved provider submits a valid offer', $r['url'] === '/provider/offers.php' && has($r['body'], 'Your offer has been sent'));
    $o1 = offerId($r1, 'prov1');
    check('Offer is saved as Pending with the right price', status('offers', $o1) === 'P' && sql_val('SELECT price FROM offers WHERE id = ?', [$o1]) === '1500.00');
    $r = sendOffer($prov1, $r1, '1400');
    check('The same provider cannot offer twice on one request', has($r['body'], 'already submitted') && (int) sql_val('SELECT COUNT(*) FROM offers WHERE service_request_id = ? AND service_provider_id = ?', [$r1, pid('prov1')]) === 1);
    check('Provider sees the offer in My offers', pageHas($prov1, '/provider/offers.php', 'Pending'));
    sendOffer($prov2, $r1, '1200', '+2 days');
    $o2 = offerId($r1, 'prov2');
    check('A second provider can also offer on the same request', $o2 > 0);
    $r = $cust1->get("/customer/request.php?id=$r1");
    check('Customer sees both offers with Accept buttons', has($r['body'], 'Rs 1,500.00') && has($r['body'], 'Rs 1,200.00') && has($r['body'], 'Accept offer'));
    $r = $cust2->post("/customer/request.php?id=$r1", ['action' => 'accept', 'offer_id' => $o1]);
    check('Another customer cannot accept an offer on someone else\'s request', $r['status'] === 404 && status('offers', $o1) === 'P');

    // ------------------------------------------------------------ 8
    section('8. Accepting an offer, booking, completion and review');
    $r = $cust1->post("/customer/request.php?id=$r1", ['action' => 'accept', 'offer_id' => $o1]);
    $okAccept = preg_match('#^/booking\.php\?id=(\d+)$#', $r['url'], $m) === 1;
    check('Accepting an offer redirects to the new booking', $okAccept && has($r['body'], 'Offer accepted'), 'ended at ' . $r['url']);
    $b1 = (int) ($m[1] ?? 0);
    check('Accepted offer is marked Accepted', status('offers', $o1) === 'A');
    check('The competing pending offer was auto-rejected', status('offers', $o2) === 'R');
    check('The request was closed', status('service_requests', $r1) === 'C');
    $bk = sql_row('SELECT b.*, o.price, o.arrival_time FROM bookings b JOIN offers o ON o.id = b.offer_id WHERE b.offer_id = ?', [$o1]);
    check('Booking starts Confirmed, with price and time copied from the offer',
        $bk !== null && $bk['status'] === 'CONF' && $bk['final_price'] === $bk['price'] && $bk['scheduled_time'] === $bk['arrival_time']);
    check('Exactly one accepted offer exists for the request', (int) sql_val("SELECT COUNT(*) FROM offers WHERE service_request_id = ? AND status = 'A'", [$r1]) === 1);

    $r = $cust1->post("/customer/request.php?id=$r1", ['action' => 'accept', 'offer_id' => $o2]);
    check('Accepting a second offer on a closed request is refused (stale form)',
        has($r['body'], 'no longer open') && (int) sql_val('SELECT COUNT(*) FROM bookings b JOIN offers o ON o.id = b.offer_id WHERE o.service_request_id = ?', [$r1]) === 1);

    $ub = "/booking.php?id=$b1";
    $r = $cust1->get($ub);
    check('Customer sees the booking with status, price and provider contact', $r['status'] === 200 && has($r['body'], 'Confirmed') && has($r['body'], 'Rs 1,500.00') && has($r['body'], acct('prov1')['email']));
    check('Provider sees the booking with the customer contact', pageHas($prov1, $ub, acct('cust1')['email']));
    check('A provider who is not part of the booking gets 404', $prov2->get($ub)['status'] === 404);
    check('A customer who is not part of the booking gets 404', $cust2->get($ub)['status'] === 404);
    check('A guest is redirected to login', $guest->get($ub)['first'] === 302);
    check('Admins are told to use the admin panel (403)', $admin->get($ub)['status'] === 403);
    check('The booking appears in both participants\' lists', pageHas($cust1, '/bookings.php', $catName2) && pageHas($prov1, '/bookings.php', $catName2));
    check('...and not in an unrelated provider\'s list', !pageHas($prov2, '/bookings.php', $catName2));

    $r = $cust1->get("/customer/review.php?booking=$b1");
    check('A booking cannot be reviewed before it is completed', has($r['body'], 'only review a booking after it has been completed'));
    $r = $cust1->post($ub, ['action' => 'cancel', 'reason' => '']);
    check('Cancelling needs a reason', has($r['body'], 'Please give a reason') && status('bookings', $b1) === 'CONF');
    $r = $prov2->post($ub, ['action' => 'complete']);
    check('A non-participant cannot complete a booking (404)', $r['status'] === 404 && status('bookings', $b1) === 'CONF');
    $r = $prov1->post($ub, ['action' => 'complete']);
    check('The provider marks the booking completed', has($r['body'], 'Booking marked as completed.') && status('bookings', $b1) === 'COMP');
    $r = $cust1->post($ub, ['action' => 'complete']);
    check('A completed booking cannot be completed again', has($r['body'], 'Only confirmed bookings can be completed'));
    $r = $cust1->post($ub, ['action' => 'cancel', 'reason' => 'Trying to cancel after completion']);
    check('A completed booking cannot be cancelled', has($r['body'], 'Only confirmed bookings can be cancelled') && status('bookings', $b1) === 'COMP');

    $ur = "/customer/review.php?booking=$b1";
    check('A provider cannot write the review (403)', $prov1->get($ur)['status'] === 403);
    check('Another customer cannot review the booking (404)', $cust2->get($ur)['status'] === 404);
    $r = $cust1->post($ur, ['rating' => '6', 'text' => '']);
    check('Review validates rating (1-5) and text', has($r['body'], 'Choose a rating from 1 to 5') && has($r['body'], 'Write 3 to 1000'));
    $r = $cust1->post($ur, ['rating' => '4', 'text' => 'Looks fine to me', 'images' => [img('png', 1), img('png', 2), img('png', 3), img('png', 4), img('png', 5), img('png', 6)]]);
    check('Review rejects more than 5 images', has($r['body'], 'at most 5 images'));
    $r = $cust1->post($ur, ['rating' => '4', 'text' => 'Looks fine to me', 'images' => [fakeImg()]]);
    check('Review rejects a fake image', has($r['body'], 'Only JPG, PNG or WebP'));
    check('No review was created by the invalid attempts', (int) sql_val('SELECT COUNT(*) FROM reviews WHERE booking_id = ?', [$b1]) === 0);
    $r = $cust1->post($ur, ['rating' => '4', 'text' => "Great work $run, on time", 'images' => [img('png', 6), img('jpg', 7)]]);
    check('The customer posts a review with 2 images', has($r['body'], 'Your review has been posted') && $r['url'] === $ub);
    $rv = sql_row('SELECT * FROM reviews WHERE booking_id = ?', [$b1]);
    check('Review stored with rating 4', $rv !== null && (int) $rv['rating'] === 4);
    $rvImgs = $rv ? array_column(sql_rows('SELECT image FROM review_images WHERE review_id = ?', [$rv['id']]), 'image') : [];
    check('2 review image rows saved and both files are on disk', count($rvImgs) === 2 && allFilesExist($rvImgs));
    $r = $cust1->get($ur);
    check('A second review of the same booking is blocked', has($r['body'], 'already reviewed'));
    $cust1->post($ur, ['rating' => '5', 'text' => 'Trying to review twice']);
    check('Posting a duplicate review does not create a second one', (int) sql_val('SELECT COUNT(*) FROM reviews WHERE booking_id = ?', [$b1]) === 1);
    check('The booking page shows the review', pageHas($cust1, $ub, "Great work $run"));
    check('The customer\'s My reviews page shows it', pageHas($cust1, '/customer/reviews.php', "Great work $run"));
    $r = $prov1->get('/provider/dashboard.php');
    check('The provider dashboard shows the new rating and review', has($r['body'], '4.0') && has($r['body'], "Great work $run"));

    // ------------------------------------------------------------ 9
    section('9. Reject, expire, cancel and reopen, delete, and simultaneous accepts');

    // R2: reject an offer, then expire the request
    $r2 = makeRequest($cust1, 'R2', $catId, $locA);
    sendOffer($prov1, $r2, '900');
    sendOffer($prov2, $r2, '800');
    $o21 = offerId($r2, 'prov1');
    $o22 = offerId($r2, 'prov2');
    $r = $cust1->post("/customer/request.php?id=$r2", ['action' => 'reject', 'offer_id' => $o22]);
    check('Customer rejects an offer', has($r['body'], 'Offer rejected.') && status('offers', $o22) === 'R');
    $r = $cust1->post("/customer/request.php?id=$r2", ['action' => 'accept', 'offer_id' => $o22]);
    check('A rejected offer cannot be accepted later', has($r['body'], 'can no longer be accepted') && status('service_requests', $r2) === 'O');
    check('A closed request no longer shows in the provider\'s list', !pageHas($prov1, '/provider/requests.php', "ZZTEST-$run-R1") && pageHas($prov1, '/provider/requests.php', "ZZTEST-$run-R2"));
    $r = $cust1->post('/admin/requests.php', ['action' => 'expire', 'id' => $r2, 'filter' => 'all']);
    check('A customer cannot expire requests (403)', $r['status'] === 403 && status('service_requests', $r2) === 'O');
    $r = $admin->post('/admin/requests.php', ['action' => 'expire', 'id' => $r2, 'filter' => 'all']);
    check('Admin expires the request', has($r['body'], 'Request expired') && status('service_requests', $r2) === 'E');
    check('Pending offers on it became Expired; rejected ones stay Rejected', status('offers', $o21) === 'E' && status('offers', $o22) === 'R');
    check('An expired request no longer shows to providers', !pageHas($prov1, '/provider/requests.php', "ZZTEST-$run-R2"));
    $r = $cust1->post("/customer/request.php?id=$r2", ['action' => 'accept', 'offer_id' => $o21]);
    check('An offer on an expired request cannot be accepted', has($r['body'], 'no longer open'));

    // R3: cancel -> reopen, then cancel after the deadline -> stays closed
    $r3 = makeRequest($cust1, 'R3', $catId, $locA);
    sendOffer($prov1, $r3, '700');
    sendOffer($prov2, $r3, '650');
    $o31 = offerId($r3, 'prov1');
    $o32 = offerId($r3, 'prov2');
    $cust1->post("/customer/request.php?id=$r3", ['action' => 'accept', 'offer_id' => $o31]);
    $b3 = (int) sql_val('SELECT id FROM bookings WHERE offer_id = ?', [$o31]);
    check('Accepting offer 1 auto-rejects offer 2', $b3 > 0 && status('offers', $o32) === 'R');
    $r = $prov1->post("/booking.php?id=$b3", ['action' => 'cancel', 'reason' => 'Tools broke down']);
    check('The provider cancels the booking with a reason', has($r['body'], 'open again') && status('bookings', $b3) === 'CANC');
    check('The cancellation reason is stored', sql_val('SELECT cancellation_reason FROM bookings WHERE id = ?', [$b3]) === 'Tools broke down');
    check('Deadline still in the future: the request reopens', status('service_requests', $r3) === 'O');
    check('The cancelled offer is Rejected and the other offer is Pending again', status('offers', $o31) === 'R' && status('offers', $o32) === 'P');
    check('The reopened request is visible to providers again', pageHas($prov2, '/provider/requests.php', "ZZTEST-$run-R3"));
    check('The cancelling provider cannot offer twice on it', has(sendOffer($prov1, $r3, '600')['body'], 'already submitted'));
    $cust1->post("/customer/request.php?id=$r3", ['action' => 'accept', 'offer_id' => $o32]);
    $b3b = (int) sql_val('SELECT id FROM bookings WHERE offer_id = ?', [$o32]);
    check('The customer can accept the other offer: new booking, request closed', $b3b > 0 && status('service_requests', $r3) === 'C');
    sql_exec('UPDATE service_requests SET urgency = NOW() - INTERVAL 1 HOUR WHERE id = ?', [$r3]);
    $r = $cust1->post("/booking.php?id=$b3b", ['action' => 'cancel', 'reason' => 'Changed my mind about it']);
    check('Cancelling after the deadline passed does NOT reopen the request', has($r['body'], 'deadline has passed') && status('service_requests', $r3) === 'C' && status('bookings', $b3b) === 'CANC');

    // R5: two customers' tabs accept different offers at the same instant
    $cust1b = new Client('cust1b');
    $cust1b->post('/login.php', ['email' => $c1['email'], 'password' => $cust1Pw]);
    $r5 = makeRequest($cust1, 'R5', $catId, $locA);
    sendOffer($prov1, $r5, '500');
    sendOffer($prov2, $r5, '450');
    $o51 = offerId($r5, 'prov1');
    $o52 = offerId($r5, 'prov2');
    $tokA = $cust1->token();
    $tokB = $cust1b->token();
    parallelPost([
        [$cust1,  "/customer/request.php?id=$r5", ['csrf_token' => $tokA, 'action' => 'accept', 'offer_id' => $o51]],
        [$cust1b, "/customer/request.php?id=$r5", ['csrf_token' => $tokB, 'action' => 'accept', 'offer_id' => $o52]],
    ]);
    check('Two simultaneous accepts: exactly ONE booking is created',
        (int) sql_val('SELECT COUNT(*) FROM bookings b JOIN offers o ON o.id = b.offer_id WHERE o.service_request_id = ?', [$r5]) === 1);
    check('...exactly one offer is Accepted and the request is Closed',
        (int) sql_val("SELECT COUNT(*) FROM offers WHERE service_request_id = ? AND status = 'A'", [$r5]) === 1 && status('service_requests', $r5) === 'C');

    // R4: admin deletes a request together with its images
    $r4 = makeRequest($cust2, 'R4', $catId, $locA, [img('png', 8)]);
    $r4Imgs = array_column(sql_rows('SELECT image FROM request_images WHERE request_id = ?', [$r4]), 'image');
    check('A second customer creates a request with an image', $r4 > 0 && allFilesExist($r4Imgs));
    $r = $admin->post('/admin/requests.php', ['action' => 'delete', 'id' => $r4, 'filter' => 'all']);
    check('Admin deletes a request: row, image rows and image files are all gone',
        has($r['body'], 'Request deleted.') && sql_val('SELECT id FROM service_requests WHERE id = ?', [$r4]) === null
        && (int) sql_val('SELECT COUNT(*) FROM request_images WHERE request_id = ?', [$r4]) === 0 && noFilesExist($r4Imgs));

    // R6: "expire all overdue" (only when it cannot touch anyone else's data)
    $r6 = makeRequest($cust2, 'R6', $catId, $locA);
    sendOffer($prov1, $r6, '300');
    sql_exec('UPDATE service_requests SET urgency = NOW() - INTERVAL 1 HOUR WHERE id = ?', [$r6]);
    $others = (int) sql_val(
        "SELECT COUNT(*) FROM service_requests WHERE status = 'O' AND urgency <= NOW() AND id <> ?
           AND customer_id NOT IN (SELECT id FROM users WHERE email LIKE 'zz\\_test\\_%')",
        [$r6]
    );
    if ($others === 0) {
        $r = $admin->post('/admin/requests.php', ['action' => 'expire_overdue', 'filter' => 'all']);
        check('"Expire overdue requests" expires the overdue request and its pending offer',
            has($r['body'], 'overdue request') && status('service_requests', $r6) === 'E' && status('offers', offerId($r6, 'prov1')) === 'E');
    } else {
        skip('"Expire overdue requests"', "$others real overdue request(s) exist and would be affected");
    }

    // ------------------------------------------------------------ 10
    section('10. Admin pages and user management');
    foreach ([
        '/admin/dashboard.php' => 'Admin dashboard', '/admin/users.php' => 'Users', '/admin/providers.php' => 'Provider verification',
        '/admin/categories.php' => 'Service categories', '/admin/services.php' => 'Services', '/admin/areas.php' => 'Service areas',
        '/admin/requests.php' => 'Service requests', '/admin/offers.php' => 'Offers', '/admin/bookings.php' => 'Bookings',
        '/admin/reviews.php' => 'Reviews',
    ] as $path => $needle) {
        $r = $admin->get($path);
        check("Admin page $path loads", $r['status'] === 200 && has($r['body'], $needle), 'HTTP ' . $r['status']);
    }
    check('Admin Reviews page shows the review', pageHas($admin, '/admin/reviews.php', "Great work $run"));
    check('Admin Bookings page shows the cancelled booking and its reason', pageHas($admin, '/admin/bookings.php?status=CANC', 'Tools broke down'));
    check('Admin user search finds the test users', pageHas($admin, '/admin/users.php?q=zz_test_' . $run, acct('prov1')['email']) && pageHas($admin, '/admin/users.php?q=zz_test_' . $run, acct('cust1')['email']));

    $c2 = uid('cust2');
    $r = $admin->post('/admin/users.php', ['action' => 'toggle_active', 'id' => $c2, 'return_query' => '']);
    check('Admin deactivates a user', has($r['body'], 'User deactivated.') && (int) sql_val('SELECT is_active FROM users WHERE id = ?', [$c2]) === 0);
    check('A deactivated user\'s open session stops working immediately', $cust2->get('/customer/dashboard.php')['first'] === 302);
    $t = new Client('deactivated');
    check('A deactivated user cannot log in', has($t->post('/login.php', ['email' => acct('cust2')['email'], 'password' => PW])['body'], 'deactivated'));
    $r = $admin->post('/admin/users.php', ['action' => 'toggle_active', 'id' => $c2, 'return_query' => '']);
    check('Admin reactivates the user', has($r['body'], 'User activated.'));
    $t = new Client('reactivated');
    check('...and they can log in again', $t->post('/login.php', ['email' => acct('cust2')['email'], 'password' => PW])['url'] === '/customer/dashboard.php');
    $r = $admin->post('/admin/users.php', ['action' => 'toggle_active', 'id' => uid('admin'), 'return_query' => '']);
    check('Admin accounts cannot be deactivated through the UI', has($r['body'], 'Administrator accounts cannot be changed'));
    $r = $cust1->post('/admin/users.php', ['action' => 'delete', 'id' => $c2, 'return_query' => '']);
    check('A customer cannot delete users (403)', $r['status'] === 403 && sql_val('SELECT id FROM users WHERE id = ?', [$c2]) !== null);

    $r = $admin->post('/admin/reviews.php', ['action' => 'delete', 'id' => (int) $rv['id']]);
    check('Admin deletes a review: row, image rows and image files are gone',
        has($r['body'], 'Review deleted.') && sql_val('SELECT id FROM reviews WHERE id = ?', [$rv['id']]) === null && noFilesExist($rvImgs));
    check('The booking can be reviewed again after its review is removed', $cust1->get($ur)['status'] === 200);
    $cust1->post($ur, ['rating' => '5', 'text' => "Second review $run", 'images' => [img('png', 9)]]);
    check('A fresh review can be posted', (int) sql_val('SELECT COUNT(*) FROM reviews WHERE booking_id = ?', [$b1]) === 1);

    // ------------------------------------------------------------ 11
    section('11. Database constraints (enforced by MySQL itself, not only by PHP)');
    sql_exec(
        'INSERT INTO service_requests (customer_id, description, min_budget, max_budget, urgency, category_id, location) VALUES (?, ?, 100, 200, ?, ?, ?)',
        [uid('cust1'), "ZZTEST-$run-DB constraint request", date('Y-m-d H:i:s', strtotime('+1 day')), $catId, 'x']
    );
    $rdb = (int) T::$pdo->lastInsertId();
    $pp = pid('prov1');
    $insOffer = fn(string $price, string $status = 'P') => sql_exec(
        'INSERT INTO offers (service_request_id, service_provider_id, price, arrival_time, status) VALUES (?, ?, ?, NOW(), ?)',
        [$rdb, $pp, $price, $status]
    );
    expectDbError('Offer price 0 is rejected', fn() => $insOffer('0'), 3819);
    expectDbError('Negative offer price is rejected', fn() => $insOffer('-1'), 3819);
    expectDbError('Invalid offer status is rejected', fn() => $insOffer('10', 'X'), 3819);
    $insOffer('10');
    $odb = (int) T::$pdo->lastInsertId();
    check('A valid offer is accepted by the database', $odb > 0);
    expectDbError('A second offer from the same provider on the same request is rejected', fn() => $insOffer('11'), 1062);
    expectDbError('Booking with final price 0 is rejected', fn() => sql_exec('INSERT INTO bookings (offer_id, scheduled_time, final_price) VALUES (?, NOW(), 0)', [$odb]), 3819);
    expectDbError('Booking for a non-existent offer is rejected (foreign key)', fn() => sql_exec('INSERT INTO bookings (offer_id, scheduled_time, final_price) VALUES (999999999, NOW(), 5)'), 1452);
    sql_exec('INSERT INTO bookings (offer_id, scheduled_time, final_price) VALUES (?, NOW(), 10)', [$odb]);
    $bdb = (int) T::$pdo->lastInsertId();
    expectDbError('A second booking for the same offer is rejected', fn() => sql_exec('INSERT INTO bookings (offer_id, scheduled_time, final_price) VALUES (?, NOW(), 10)', [$odb]), 1062);
    expectDbError('Invalid booking status is rejected', fn() => sql_exec("UPDATE bookings SET status = 'XXXX' WHERE id = ?", [$bdb]), 3819);
    expectDbError('Review rating 0 is rejected', fn() => sql_exec("INSERT INTO reviews (booking_id, text, rating) VALUES (?, 't', 0)", [$bdb]), 3819);
    expectDbError('Review rating 6 is rejected', fn() => sql_exec("INSERT INTO reviews (booking_id, text, rating) VALUES (?, 't', 6)", [$bdb]), 3819);
    sql_exec("INSERT INTO reviews (booking_id, text, rating) VALUES (?, 't', 5)", [$bdb]);
    expectDbError('A second review for the same booking is rejected', fn() => sql_exec("INSERT INTO reviews (booking_id, text, rating) VALUES (?, 't', 5)", [$bdb]), 1062);
    expectDbError('Invalid user role is rejected', fn() => sql_exec("UPDATE users SET role = 'X' WHERE id = ?", [uid('cust2')]), 3819);
    expectDbError('Invalid request status is rejected', fn() => sql_exec("UPDATE service_requests SET status = 'Z' WHERE id = ?", [$rdb]), 3819);
    expectDbError('Duplicate email is rejected', fn() => sql_exec("UPDATE users SET email = ? WHERE id = ?", [acct('cust1')['email'], uid('cust2')]), 1062);
    sql_exec('DELETE FROM service_requests WHERE id = ?', [$rdb]);
    check('Deleting a request cascades to its offers, booking and review',
        (int) sql_val('SELECT COUNT(*) FROM offers WHERE id = ?', [$odb]) === 0
        && (int) sql_val('SELECT COUNT(*) FROM bookings WHERE id = ?', [$bdb]) === 0
        && (int) sql_val('SELECT COUNT(*) FROM reviews WHERE booking_id = ?', [$bdb]) === 0);

    // ------------------------------------------------------------ 12
    section('12. Security extras');
    $evil = T::$root . '/public/uploads/users/zz_exec_' . $run . '.php';
    if (@file_put_contents($evil, '<?php echo "EXEC" . "UTED_' . $run . '";') !== false) {
        @chmod($evil, 0644);
        $r = $guest->get('/uploads/users/zz_exec_' . $run . '.php');
        check('A .php file inside uploads/ is NOT executed', !str_contains($r['body'], 'EXECUTED_' . $run), 'HTTP ' . $r['status']);
        @unlink($evil);
    } else {
        skip('PHP execution inside uploads/', 'could not write a test file into public/uploads/users');
    }
    $r = $guest->get('/uploads/users/');
    check('Upload folders have no directory listing', !has($r['body'], 'Index of') && in_array($r['status'], [403, 404], true), 'HTTP ' . $r['status']);
    foreach (['/config/database.php', '/config/app.php', '/includes/db.php', '/app/offers.php', '/database/schema.sql', '/tests/e2e.php', '/.git/config'] as $p) {
        $r = $guest->get($p);
        check("Private file $p is not reachable from the web", in_array($r['status'], [403, 404], true), 'HTTP ' . $r['status']);
    }

    // ------------------------------------------------------------ 13
    section('13. Deleting data cleans up everything (cascade and files)');
    $offerIds = array_map('intval', array_column(sql_rows('SELECT o.id FROM offers o JOIN service_requests r ON r.id = o.service_request_id WHERE r.category_id = ?', [$catId]), 'id'));
    $bookingIds = $offerIds ? array_map('intval', array_column(sql_rows('SELECT id FROM bookings WHERE offer_id IN (' . implode(',', $offerIds) . ')'), 'id')) : [];
    $reqFiles = array_column(sql_rows('SELECT ri.image FROM request_images ri JOIN service_requests r ON r.id = ri.request_id WHERE r.category_id = ?', [$catId]), 'image');
    $revFiles = array_column(sql_rows(
        'SELECT rv.image FROM review_images rv JOIN reviews r ON r.id = rv.review_id JOIN bookings b ON b.id = r.booking_id
           JOIN offers o ON o.id = b.offer_id JOIN service_requests q ON q.id = o.service_request_id WHERE q.category_id = ?', [$catId]), 'image');
    check('Before deletion the category has offers, bookings and image files', count($offerIds) > 0 && count($bookingIds) > 0 && allFilesExist($reqFiles) && allFilesExist($revFiles));

    $r = $admin->post('/admin/categories.php', ['action' => 'delete', 'id' => $catId]);
    check('Admin deletes the category', has($r['body'], 'Category deleted.'));
    check('Its services and requests are gone (cascade)',
        (int) sql_val('SELECT COUNT(*) FROM services WHERE category_id = ?', [$catId]) === 0
        && (int) sql_val('SELECT COUNT(*) FROM service_requests WHERE category_id = ?', [$catId]) === 0);
    check('Their offers and bookings are gone (cascade)',
        (int) sql_val('SELECT COUNT(*) FROM offers WHERE id IN (' . implode(',', $offerIds ?: [0]) . ')') === 0
        && (int) sql_val('SELECT COUNT(*) FROM bookings WHERE id IN (' . implode(',', $bookingIds ?: [0]) . ')') === 0);
    check('Request and review image files were removed from disk', noFilesExist($reqFiles) && noFilesExist($revFiles));
    check('Providers lost the service from their profiles (junction rows)', (int) sql_val('SELECT COUNT(*) FROM provider_profile_services WHERE service_id = ?', [$svcId]) === 0);

    foreach ([$areaAId, $areaBId] as $aid) {
        $r = $admin->post('/admin/areas.php', ['action' => 'delete', 'id' => $aid]);
        check("Admin deletes service area #$aid", has($r['body'], 'Service area deleted.'));
    }
    check('Provider area links were removed with the areas', (int) sql_val('SELECT COUNT(*) FROM provider_profile_service_areas WHERE service_area_id IN (?, ?)', [$areaAId, $areaBId]) === 0);

    $pics = array_column(sql_rows("SELECT profile_picture FROM users WHERE email LIKE ? AND profile_picture <> ''", ["zz_test_{$run}_%"]), 'profile_picture');
    check('Test users have profile pictures on disk before deletion', allFilesExist($pics));
    $ids = array_map('intval', array_column(sql_rows('SELECT id FROM users WHERE email LIKE ? AND is_admin = 0', ["zz_test_{$run}_%"]), 'id'));
    $deleted = 0;
    foreach ($ids as $id) {
        $r = $admin->post('/admin/users.php', ['action' => 'delete', 'id' => $id, 'return_query' => '']);
        $deleted += has($r['body'], 'User deleted.') ? 1 : 0;
    }
    check('Admin deletes every test user', $deleted === count($ids) && $ids !== [], "$deleted of " . count($ids));
    check('Their profile pictures were removed from disk', noFilesExist($pics));

} catch (Throwable $e) {
    check('Test run aborted unexpectedly: ' . $e->getMessage(), false, basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if (!$KEEP) {
        finalCleanup();
    }
}

// ---------------------------------------------------------------- summary

echo "\n" . str_repeat('=', 64) . "\n";
$secs = microtime(true) - T::$start;
$line = sprintf('Passed: %d    Failed: %d    Skipped: %d    (%.1f s)', T::$pass, T::$fail, T::$skip, $secs);
echo color(T::$fail === 0 ? 'g' : 'r', $line) . "\n";
if (T::$fail > 0) {
    echo "\nFailed checks:\n";
    foreach (T::$failures as $f) {
        echo '  - ' . $f . "\n";
    }
}
echo $KEEP ? "\n--keep was used: test data was left in place (zz_test_{$run}_*).\n" : "\nAll test data was cleaned up.\n";
exit(T::$fail === 0 ? 0 : 1);
