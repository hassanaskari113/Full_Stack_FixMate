<?php
require_once __DIR__ . '/../includes/bootstrap.php';

redirectIfLoggedIn();

$errors = [];

if (isPost()) {
    verifyCsrf();

    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '')    { $errors['email'] = 'Enter your email.'; }
    if ($password === '') { $errors['password'] = 'Enter your password.'; }

    if (!$errors) {
        $stmt = db()->prepare('SELECT id, password_hash, is_active FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if ($row) {
            $valid = password_verify($password, $row['password_hash']);
        } else {
            password_hash($password, PASSWORD_DEFAULT);   // similar delay, so timing doesn't reveal which emails exist
            $valid = false;
        }

        if (!$valid) {
            // Same message for "no such email" and "wrong password".
            $errors['general'] = 'Invalid email or password.';
        } elseif ((int) $row['is_active'] !== 1) {
            $errors['general'] = 'This account has been deactivated. Please contact support.';
        } else {
            if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
            }
            loginUser($row);
            flash('success', 'Welcome back!');
            redirect(dashboardPath(currentUser()));
        }
    }
}

$pageTitle = 'Log in';
require_once BASE_PATH . '/includes/header.php';
?>
<div class="auth-split">
  <?php authAside('Pick up where you left off.', 'Your requests, quotes and bookings are waiting in your dashboard.', ['Compare quotes side by side', 'Book the provider you trust', 'Review the work afterwards']); ?>
  <div class="auth-main">
  <div class="auth-wrap">
    <div class="card">
      <h1>Welcome back</h1>
      <p class="muted text-center">Log in to manage your requests and bookings.</p>

      <?php if (isset($errors['general'])): ?>
        <div class="alert alert-error"><?= e($errors['general']) ?></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrfField() ?>
        <div class="form-group">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" class="<?= isset($errors['email']) ? 'input-error' : '' ?>" value="<?= old('email') ?>" autocomplete="email" autofocus required>
          <?= fieldError($errors, 'email') ?>
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" class="<?= isset($errors['password']) ? 'input-error' : '' ?>" autocomplete="current-password" required>
          <?= fieldError($errors, 'password') ?>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Log in</button>
      </form>
    </div>
    <p class="auth-footer">New to FixMate? <a href="/signup.php">Create an account</a></p>
  </div>
  </div>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
