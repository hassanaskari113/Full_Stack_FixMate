<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once BASE_PATH . '/includes/upload.php';

redirectIfLoggedIn();

$errors = [];
$selectedRole = ($_GET['role'] ?? 'C') === 'P' ? 'P' : 'C';   // landing page links use ?role=P

if (isPost()) {
    // A request bigger than PHP's post_max_size arrives with an empty $_POST.
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors['general'] = 'The submitted data was too large. Please use a smaller profile picture.';
    } else {
        verifyCsrf();

        $first    = trim((string) ($_POST['first_name'] ?? ''));
        $last     = trim((string) ($_POST['last_name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone    = preg_replace('/[\s\-()]/', '', (string) ($_POST['phone_number'] ?? ''));
        $role     = (string) ($_POST['role'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');
        $selectedRole = $role === 'P' ? 'P' : 'C';

        if ($first === '' || mb_strlen($first) > 150) {
            $errors['first_name'] = 'Enter your first name (max 150 characters).';
        }
        if ($last === '' || mb_strlen($last) > 150) {
            $errors['last_name'] = 'Enter your last name (max 150 characters).';
        }
        if (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $username)) {
            $errors['username'] = '3-30 characters: letters, numbers, underscore or dot.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($phone !== '' && !preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
            $errors['phone_number'] = 'Enter 10-15 digits, optionally starting with +.';
        }
        if (!in_array($role, ['C', 'P'], true)) {
            $errors['role'] = 'Please choose Customer or Provider.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        } elseif (strlen($password) > 72) {
            $errors['password'] = 'Password must be at most 72 characters.';
        } elseif ($password !== $confirm) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }

        // Optional profile picture: validate now, save only if everything else passes.
        $picture = $_FILES['profile_picture'] ?? null;
        $hasPicture = $picture && $picture['error'] !== UPLOAD_ERR_NO_FILE;
        if ($hasPicture && ($msg = validateImageUpload($picture))) {
            $errors['profile_picture'] = $msg;
        }

        // Uniqueness (the database also enforces this, as a final safety net).
        if (!$errors) {
            $stmt = db()->prepare(
                'SELECT username, email, phone_number FROM users
                 WHERE username = ? OR email = ? OR phone_number = ?'
            );
            $stmt->execute([$username, $email, $phone === '' ? null : $phone]);
            foreach ($stmt->fetchAll() as $row) {
                if (strcasecmp($row['username'], $username) === 0) {
                    $errors['username'] = 'That username is already taken.';
                }
                if (strcasecmp($row['email'], $email) === 0) {
                    $errors['email'] = 'An account with this email already exists.';
                }
                if ($phone !== '' && $row['phone_number'] === $phone) {
                    $errors['phone_number'] = 'That phone number is already registered.';
                }
            }
        }

        if (!$errors) {
            $picturePath = '';
            try {
                if ($hasPicture) {
                    $picturePath = storeImage($picture, 'users');
                }
                $stmt = db()->prepare(
                    'INSERT INTO users
                       (username, first_name, last_name, email, password_hash,
                        profile_picture, phone_number, role)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $username, $first, $last, $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $picturePath,
                    $phone === '' ? null : $phone,   // NULL, not '', so the UNIQUE index allows many blanks
                    $role,
                ]);
                $newId = (int) db()->lastInsertId();

                loginUser(['id' => $newId]);
                flash('success', 'Welcome to FixMate, ' . $first . '! Your account has been created.');
                redirect(dashboardPath(currentUser()));
            } catch (PDOException $ex) {
                deleteUploadedFile($picturePath);
                if ($ex->getCode() === '23000') {            // lost a race on a UNIQUE key
                    $errors['general'] = 'That username, email or phone number is already registered.';
                } else {
                    error_log('Signup failed: ' . $ex->getMessage());
                    $errors['general'] = 'Something went wrong. Please try again.';
                }
            } catch (Throwable $ex) {
                deleteUploadedFile($picturePath);
                error_log('Signup failed: ' . $ex->getMessage());
                $errors['general'] = 'Something went wrong. Please try again.';
            }
        }
    }
}

$pageTitle = 'Sign up';
require_once BASE_PATH . '/includes/header.php';
$cls = fn(string $f) => isset($errors[$f]) ? ' input-error' : '';
?>
<div class="auth-split">
  <aside class="auth-aside">
    <div class="aside-inner">
      <div>
        <h2>Hire a pro, or become one.</h2>
        <p>One account, two ways to use FixMate. Choose your role when you sign up.</p>
      </div>
      <div class="aside-collage" aria-hidden="true">
        <div class="mini">
          <div class="mini-row"><strong>Kitchen tap leaking</strong><span class="badge badge-success">Open</span></div>
          <div class="mini-meta"><span><?= icon('pin', 15) ?> Gulberg</span><span><?= icon('wallet', 15) ?> Rs 1,000 to Rs 2,500</span></div>
        </div>
        <div class="mini mini-quote">
          <div><span class="mini-label">Quote received</span><strong>Rs 1,500</strong></div>
          <span>Arrives today<br>4:00 PM</span>
        </div>
        <div class="mini mini-done">
          <span class="tick"><?= icon('check', 18) ?></span>
          <div><strong>Booking confirmed</strong><span class="stars">&#9733;&#9733;&#9733;&#9733;&#9733;</span></div>
        </div>
      </div>
      <ul class="aside-list">
        <li><span class="tick"><?= icon('check', 14) ?></span><span>Hire: post a job and get quotes from approved providers.</span></li>
        <li><span class="tick"><?= icon('check', 14) ?></span><span>Work: set up your services and areas, then get matched requests.</span></li>
        <li><span class="tick"><?= icon('check', 14) ?></span><span>Providers are reviewed by an administrator before they can quote.</span></li>
      </ul>
    </div>
  </aside>
  <div class="auth-main">
  <div class="auth-wrap">
    <div class="card">
      <h1>Create your account</h1>
      <p class="muted text-center">Join FixMate as a customer or a service provider.</p>

      <?php if (isset($errors['general'])): ?>
        <div class="alert alert-error"><?= e($errors['general']) ?></div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrfField() ?>

        <div class="form-group">
          <label>I want to</label>
          <div class="role-choice">
            <label class="role-option">
              <input type="radio" name="role" value="C" <?= $selectedRole === 'C' ? 'checked' : '' ?>>
              <span><strong>Hire</strong><small>I'm a customer</small></span>
            </label>
            <label class="role-option">
              <input type="radio" name="role" value="P" <?= $selectedRole === 'P' ? 'checked' : '' ?>>
              <span><strong>Work</strong><small>I'm a provider</small></span>
            </label>
          </div>
          <?= fieldError($errors, 'role') ?>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="first_name">First name</label>
            <input type="text" id="first_name" name="first_name" class="<?= $cls('first_name') ?>" value="<?= old('first_name') ?>" maxlength="150" required>
            <?= fieldError($errors, 'first_name') ?>
          </div>
          <div class="form-group">
            <label for="last_name">Last name</label>
            <input type="text" id="last_name" name="last_name" class="<?= $cls('last_name') ?>" value="<?= old('last_name') ?>" maxlength="150" required>
            <?= fieldError($errors, 'last_name') ?>
          </div>
        </div>

        <div class="form-group">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" class="<?= $cls('username') ?>" value="<?= old('username') ?>" maxlength="30" autocomplete="username" required>
          <?= fieldError($errors, 'username') ?>
        </div>

        <div class="form-group">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" class="<?= $cls('email') ?>" value="<?= old('email') ?>" maxlength="254" autocomplete="email" required>
          <?= fieldError($errors, 'email') ?>
        </div>

        <div class="form-group">
          <label for="phone_number">Phone <span class="muted">(optional)</span></label>
          <input type="tel" id="phone_number" name="phone_number" class="<?= $cls('phone_number') ?>" value="<?= old('phone_number') ?>" placeholder="+923001234567" autocomplete="tel">
          <?= fieldError($errors, 'phone_number') ?>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" class="<?= $cls('password') ?>" minlength="8" autocomplete="new-password" required>
            <?= fieldError($errors, 'password') ?>
          </div>
          <div class="form-group">
            <label for="password_confirm">Confirm password</label>
            <input type="password" id="password_confirm" name="password_confirm" class="<?= $cls('password_confirm') ?>" autocomplete="new-password" required>
            <?= fieldError($errors, 'password_confirm') ?>
          </div>
        </div>
        <p class="form-hint" style="margin-top:-.5rem;margin-bottom:1rem">At least 8 characters.</p>

        <div class="form-group">
          <label for="profile_picture">Profile picture <span class="muted">(optional)</span></label>
          <input type="file" id="profile_picture" name="profile_picture" class="<?= $cls('profile_picture') ?>" accept="image/jpeg,image/png,image/webp">
          <p class="form-hint">JPG, PNG or WebP, up to 2 MB. You'll need to choose it again if the form shows an error.</p>
          <?= fieldError($errors, 'profile_picture') ?>
        </div>

        <button type="submit" class="btn btn-primary btn-block btn-lg">Create account</button>
      </form>
    </div>
    <p class="auth-footer">Already have an account? <a href="/login.php">Log in</a></p>
  </div>
  </div>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
