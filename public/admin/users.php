<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once BASE_PATH . '/app/users.php';
requireAdmin();

$me      = currentUser();
$roles   = ['all' => 'All', 'C' => 'Customers', 'P' => 'Providers', 'A' => 'Admins'];

$role = isset($roles[$_GET['role'] ?? '']) ? $_GET['role'] : 'all';
$q    = trim((string) ($_GET['q'] ?? ''));

if (isPost()) {
    verifyCsrf();
    $id     = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    // Rebuild the return URL from known keys only
    parse_str((string) ($_POST['return_query'] ?? ''), $rq);
    $back = [];
    if (isset($rq['role']) && isset($roles[$rq['role']])) { $back['role'] = $rq['role']; }
    if (isset($rq['q']) && is_string($rq['q']) && $rq['q'] !== '') { $back['q'] = mb_substr($rq['q'], 0, 100); }
    $backUrl = '/admin/users.php' . ($back ? '?' . http_build_query($back) : '');

    $stmt = db()->prepare('SELECT id, is_admin, is_active, first_name, username FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();

    if (!$target) {
        flash('error', 'That user no longer exists.');
    } elseif ((int) $target['is_admin'] === 1 || $target['id'] == $me['id']) {
        flash('error', 'Administrator accounts cannot be changed here.');
    } elseif ($action === 'toggle_active') {
        db()->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        flash('success', ((int) $target['is_active'] === 1 ? 'User deactivated.' : 'User activated.'));
    } elseif ($action === 'delete') {
        try {
            deleteUserWithFiles($id);
            flash('success', 'User deleted.');
        } catch (Throwable $ex) {
            error_log('Delete user failed: ' . $ex->getMessage());
            flash('error', 'Could not delete the user.');
        }
    } else {
        flash('error', 'Unknown action.');
    }
    redirect($backUrl);
}

$where  = [];
$params = [];
if ($role === 'A')      { $where[] = 'u.is_admin = 1'; }
elseif ($role === 'C')  { $where[] = "u.is_admin = 0 AND u.role = 'C'"; }
elseif ($role === 'P')  { $where[] = "u.is_admin = 0 AND u.role = 'P'"; }
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}

$sql = 'SELECT u.id, u.username, u.first_name, u.last_name, u.email, u.phone_number,
               u.profile_picture, u.role, u.is_admin, u.is_active, u.joined_at, u.last_login,
               pp.verification_status
        FROM users u
        LEFT JOIN provider_profiles pp ON pp.user_id = u.id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY u.id DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$returnQuery = http_build_query(array_filter(['role' => $role, 'q' => $q], fn($v) => $v !== '' && $v !== 'all'));

$pageTitle   = 'Users';
$adminActive = 'users';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/admin_start.php';
?>
<div class="page-head"><h1>Users</h1></div>

<div class="pill-tabs">
  <?php foreach ($roles as $key => $label): ?>
    <a href="/admin/users.php?<?= e(http_build_query(array_filter(['role' => $key, 'q' => $q], fn($v) => $v !== '' && $v !== 'all'))) ?>"<?= $role === $key ? ' class="active"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<form method="get" class="toolbar">
  <input type="hidden" name="role" value="<?= e($role) ?>">
  <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search name, username or email">
  <button type="submit" class="btn btn-primary no-loading">Search</button>
  <?php if ($q !== ''): ?><a class="btn btn-outline" href="/admin/users.php?role=<?= e($role) ?>">Clear</a><?php endif; ?>
</form>

<?php if (!$users): ?>
  <div class="card empty-state"><h3>No users found</h3><p>Try a different filter or search.</p></div>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>User</th><th>Role</th><th>Phone</th><th>Joined</th><th>Last login</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): $isProtected = (int) $u['is_admin'] === 1; ?>
        <tr>
          <td>
            <div class="person">
              <?= avatar($u, 'avatar avatar-sm') ?>
              <div class="person-info">
                <strong><?= e(userDisplayName($u)) ?></strong>
                <small><?= e($u['email']) ?> · @<?= e($u['username']) ?></small>
              </div>
            </div>
          </td>
          <td>
            <?php if ($isProtected): ?>
              <?= badge('Admin', 'info') ?>
            <?php elseif ($u['role'] === 'P'): ?>
              <?= badge('Provider', 'muted') ?>
              <?= $u['verification_status'] ? verificationBadge($u['verification_status']) : '<small class="muted">no profile</small>' ?>
            <?php else: ?>
              <?= badge('Customer', 'muted') ?>
            <?php endif; ?>
          </td>
          <td><?= $u['phone_number'] ? e($u['phone_number']) : '<span class="muted">-</span>' ?></td>
          <td><?= e(formatDateTime($u['joined_at'])) ?></td>
          <td><?= $u['last_login'] ? e(formatDateTime($u['last_login'])) : '<span class="muted">never</span>' ?></td>
          <td><?= (int) $u['is_active'] === 1 ? badge('Active', 'success') : badge('Deactivated', 'danger') ?></td>
          <td>
            <?php if ($isProtected): ?>
              <span class="muted">-</span>
            <?php else: ?>
              <div class="row-actions">
                <form method="post" data-confirm="<?= (int) $u['is_active'] === 1 ? 'Deactivate this user? They will no longer be able to log in.' : 'Activate this user again?' ?>">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                  <button type="submit" class="btn btn-outline btn-sm"><?= (int) $u['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <form method="post" data-confirm="Permanently delete <?= e(userDisplayName($u)) ?>? All of their requests, offers, bookings, reviews and images will be deleted too. This cannot be undone.">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <input type="hidden" name="return_query" value="<?= e($returnQuery) ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($users) === 200): ?>
    <p class="muted" style="margin-top:.75rem">Showing the 200 most recent matches. Use search to narrow the list.</p>
  <?php endif; ?>
<?php endif; ?>
<?php
require_once BASE_PATH . '/includes/admin_end.php';
require_once BASE_PATH . '/includes/footer.php';
