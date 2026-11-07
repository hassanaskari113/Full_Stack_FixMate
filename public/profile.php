<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requireLogin();

$user = currentUser();
$pe = [];   // profile-form errors
$pw = [];   // password-form errors
$vals = [
    'first_name'   => $user['first_name'],
    'last_name'    => $user['last_name'],
    'username'     => $user['username'],
    'phone_number' => $user['phone_number'] ?? '',
];

if (isPost()) {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $pe['general'] = 'The submitted data was too large. Please use a smaller picture.';
    } else {
        verifyCsrf();
        $form = (string) ($_POST['form'] ?? '');

        if ($form === 'profile') {
            $vals['first_name']   = trim((string) ($_POST['first_name'] ?? ''));
            $vals['last_name']    = trim((string) ($_POST['last_name'] ?? ''));
            $vals['username']     = trim((string) ($_POST['username'] ?? ''));
            $vals['phone_number'] = preg_replace('/[\s\-()]/', '', (string) ($_POST['phone_number'] ?? ''));

            if ($vals['first_name'] === '' || mb_strlen($vals['first_name']) > 150) { $pe['first_name'] = 'Enter your first name (max 150 characters).'; }
            if ($vals['last_name'] === '' || mb_strlen($vals['last_name']) > 150)   { $pe['last_name'] = 'Enter your last name (max 150 characters).'; }
            if (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $vals['username'])) { $pe['username'] = '3-30 characters: letters, numbers, underscore or dot.'; }
            if ($vals['phone_number'] !== '' && !preg_match('/^\+?[0-9]{10,15}$/', $vals['phone_number'])) {
                $pe['phone_number'] = 'Enter 10-15 digits, optionally starting with +.';
            }

            $picture    = $_FILES['profile_picture'] ?? null;
            $hasPicture = $picture && $picture['error'] !== UPLOAD_ERR_NO_FILE;
            if ($hasPicture && ($msg = validateImageUpload($picture))) { $pe['profile_picture'] = $msg; }

            if (!$pe) {
                $stmt = db()->prepare('SELECT username, phone_number FROM users WHERE (username = ? OR phone_number = ?) AND id <> ?');
                $stmt->execute([$vals['username'], $vals['phone_number'] === '' ? null : $vals['phone_number'], $user['id']]);
                foreach ($stmt->fetchAll() as $row) {
                    if (strcasecmp($row['username'], $vals['username']) === 0) { $pe['username'] = 'That username is already taken.'; }
                    if ($vals['phone_number'] !== '' && $row['phone_number'] === $vals['phone_number']) { $pe['phone_number'] = 'That phone number is already registered.'; }
                }
            }

            if (!$pe) {
                $stored = '';
                try {
                    $newPic = $user['profile_picture'];
                    if ($hasPicture) {
                        $stored = storeImage($picture, 'users');
                        $newPic = $stored;
                    } elseif (!empty($_POST['remove_picture'])) {
                        $newPic = '';
                    }
                    db()->prepare('UPDATE users SET first_name = ?, last_name = ?, username = ?, phone_number = ?, profile_picture = ? WHERE id = ?')
                        ->execute([$vals['first_name'], $vals['last_name'], $vals['username'],
                                   $vals['phone_number'] === '' ? null : $vals['phone_number'], $newPic, $user['id']]);
                    if ($newPic !== $user['profile_picture']) {
                        deleteUploadedFile($user['profile_picture']);
                    }
                    flash('success', 'Profile updated.');
                    redirect('/profile.php');
                } catch (Throwable $ex) {
                    deleteUploadedFile($stored);
                    error_log('Profile update failed: ' . $ex->getMessage());
                    $pe['general'] = ($ex instanceof PDOException && $ex->getCode() === '23000')
                        ? 'That username or phone number is already registered.'
                        : 'Something went wrong. Please try again.';
                }
            }

        } elseif ($form === 'password') {
            $current = (string) ($_POST['current_password'] ?? '');
            $new     = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['new_password_confirm'] ?? '');

            $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([$user['id']]);
            $hash = (string) $stmt->fetchColumn();

            if (!password_verify($current, $hash)) { $pw['current_password'] = 'Your current password is incorrect.'; }
            if (strlen($new) < 8)       { $pw['new_password'] = 'Password must be at least 8 characters.'; }
            elseif (strlen($new) > 72)  { $pw['new_password'] = 'Password must be at most 72 characters.'; }
            elseif ($new !== $confirm)  { $pw['new_password_confirm'] = 'Passwords do not match.'; }

            if (!$pw) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
                session_regenerate_id(true);
                flash('success', 'Password changed.');
                redirect('/profile.php');
            }
        }
    }
}

$pageTitle = 'Profile settings';
require_once BASE_PATH . '/includes/header.php';
$roleNavActive = 'profile';
require BASE_PATH . '/includes/role_nav.php';
$c = fn(array $errs, string $f) => isset($errs[$f]) ? 'input-error' : '';
?>
<div class="container section" style="max-width:720px">
  <h1>Profile settings</h1>

  <div class="card mb-1">
    <div class="card-head">
      <div class="person">
        <?= avatar($user) ?>
        <div class="person-info"><strong><?= e(userDisplayName($user)) ?></strong><small><?= e($user['email']) ?></small></div>
      </div>
      <?= (int) $user['is_admin'] === 1 ? badge('Admin', 'info') : badge($user['role'] === 'P' ? 'Provider' : 'Customer', 'muted') ?>
    </div>

    <?php if (isset($pe['general'])): ?><div class="alert alert-error"><?= e($pe['general']) ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate>
      <?= csrfField() ?>
      <input type="hidden" name="form" value="profile">
      <div class="form-row">
        <div class="form-group">
          <label for="first_name">First name</label>
          <input type="text" id="first_name" name="first_name" class="<?= $c($pe, 'first_name') ?>" value="<?= e($vals['first_name']) ?>" maxlength="150" required>
          <?= fieldError($pe, 'first_name') ?>
        </div>
        <div class="form-group">
          <label for="last_name">Last name</label>
          <input type="text" id="last_name" name="last_name" class="<?= $c($pe, 'last_name') ?>" value="<?= e($vals['last_name']) ?>" maxlength="150" required>
          <?= fieldError($pe, 'last_name') ?>
        </div>
      </div>
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" class="<?= $c($pe, 'username') ?>" value="<?= e($vals['username']) ?>" maxlength="30" required>
        <?= fieldError($pe, 'username') ?>
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" value="<?= e($user['email']) ?>" disabled>
        <p class="form-hint">Your email is your login and can't be changed here.</p>
      </div>
      <div class="form-group">
        <label for="phone_number">Phone <span class="muted">(optional)</span></label>
        <input type="tel" id="phone_number" name="phone_number" class="<?= $c($pe, 'phone_number') ?>" value="<?= e($vals['phone_number']) ?>" placeholder="+923001234567">
        <?= fieldError($pe, 'phone_number') ?>
      </div>
      <div class="form-group">
        <label for="profile_picture">Profile picture</label>
        <input type="file" id="profile_picture" name="profile_picture" class="<?= $c($pe, 'profile_picture') ?>" accept="image/jpeg,image/png,image/webp">
        <p class="form-hint">JPG, PNG or WebP, up to 2 MB. Choosing a new picture replaces the current one.</p>
        <?= fieldError($pe, 'profile_picture') ?>
        <?php if ($user['profile_picture'] !== ''): ?>
          <label style="font-weight:400;margin-top:.5rem"><input type="checkbox" name="remove_picture" value="1" style="width:auto"> Remove my current picture</label>
        <?php endif; ?>
      </div>
      <button type="submit" class="btn btn-primary">Save changes</button>
    </form>
  </div>

  <div class="card">
    <h3>Change password</h3>
    <form method="post" novalidate>
      <?= csrfField() ?>
      <input type="hidden" name="form" value="password">
      <div class="form-group">
        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" class="<?= $c($pw, 'current_password') ?>" autocomplete="current-password" required>
        <?= fieldError($pw, 'current_password') ?>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="new_password">New password</label>
          <input type="password" id="new_password" name="new_password" class="<?= $c($pw, 'new_password') ?>" autocomplete="new-password" required>
          <?= fieldError($pw, 'new_password') ?>
        </div>
        <div class="form-group">
          <label for="new_password_confirm">Confirm new password</label>
          <input type="password" id="new_password_confirm" name="new_password_confirm" class="<?= $c($pw, 'new_password_confirm') ?>" autocomplete="new-password" required>
          <?= fieldError($pw, 'new_password_confirm') ?>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Change password</button>
    </form>
  </div>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
