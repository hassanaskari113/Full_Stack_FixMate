<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once BASE_PATH . '/app/requests.php';
requireRole('C');

$user = currentUser();
$categories = db()->query('SELECT id, name FROM service_categories ORDER BY name')->fetchAll();
$errors = [];
$f = ['category_id' => '', 'description' => '', 'min_budget' => '', 'max_budget' => '', 'urgency' => '', 'location' => ''];
if (!isPost() && isset($_GET['category'])) { $f['category_id'] = (string) (int) $_GET['category']; }

if (isPost()) {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors['general'] = 'The submitted data was too large. Please upload fewer or smaller images.';
    } else {
        verifyCsrf();
        foreach ($f as $k => $_) { $f[$k] = trim((string) ($_POST[$k] ?? '')); }

        if (!in_array((int) $f['category_id'], array_map('intval', array_column($categories, 'id')), true)) {
            $errors['category_id'] = 'Choose a category.';
        }
        $len = mb_strlen($f['description']);
        if ($len < 10 || $len > 2000) { $errors['description'] = 'Describe the job in 10 to 2000 characters.'; }

        $min = parseMoney($f['min_budget']);
        $max = parseMoney($f['max_budget']);
        if ($min === null) { $errors['min_budget'] = 'Enter an amount above 0 (up to 99999.99).'; }
        if ($max === null) { $errors['max_budget'] = 'Enter an amount above 0 (up to 99999.99).'; }
        elseif ($min !== null && (float) $max < (float) $min) { $errors['max_budget'] = 'Maximum must not be less than the minimum.'; }

        $urgency = parseDateTimeLocal($f['urgency']);
        if ($urgency === null) { $errors['urgency'] = 'Choose a valid date and time.'; }
        elseif (strtotime($urgency) <= time()) { $errors['urgency'] = 'The deadline must be in the future.'; }

        $locLen = mb_strlen($f['location']);
        if ($locLen < 3 || $locLen > 500) { $errors['location'] = 'Enter your address or area (3 to 500 characters).'; }

        $images = normalizeMultiFiles($_FILES['images'] ?? null);
        if ($msg = validateImageList($images, 5)) { $errors['images'] = $msg; }

        if (!$errors) {
            $pdo = db();
            $stored = [];
            try {
                $pdo->beginTransaction();
                $pdo->prepare(
                    'INSERT INTO service_requests (customer_id, description, min_budget, max_budget, urgency, category_id, location)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$user['id'], $f['description'], $min, $max, $urgency, (int) $f['category_id'], $f['location']]);
                $rid = (int) $pdo->lastInsertId();

                $ins = $pdo->prepare('INSERT INTO request_images (image, request_id) VALUES (?, ?)');
                foreach ($images as $img) {
                    $path = storeImage($img, 'request_images');
                    $stored[] = $path;
                    $ins->execute([$path, $rid]);
                }
                $pdo->commit();

                flash('success', 'Your request is live. Matching providers can now send you offers.');
                redirect('/customer/request.php?id=' . $rid);
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                foreach ($stored as $p) { deleteUploadedFile($p); }
                error_log('Create request failed: ' . $ex->getMessage());
                $errors['general'] = 'Something went wrong while saving your request. Please try again.';
            }
        }
    }
}

$pageTitle = 'New request';
require_once BASE_PATH . '/includes/header.php';
$customerNavActive = 'new';
require BASE_PATH . '/includes/customer_nav.php';
$c = fn(string $k) => isset($errors[$k]) ? 'input-error' : '';
?>
<div class="container section" style="max-width:780px">
  <h1>Post a service request</h1>
  <p class="muted">The more detail you give, the better the offers you'll receive.</p>

  <?php if (isset($errors['general'])): ?><div class="alert alert-error"><?= e($errors['general']) ?></div><?php endif; ?>

  <?php if (!$categories): ?>
    <div class="card empty-state"><h3>No categories available</h3><p>An administrator needs to add service categories first.</p></div>
  <?php else: ?>
  <form method="post" enctype="multipart/form-data" class="card" novalidate>
    <?= csrfField() ?>

    <div class="form-group">
      <label for="category_id">Category</label>
      <select id="category_id" name="category_id" class="<?= $c('category_id') ?>" required>
        <option value="">Choose a category…</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= (int) $cat['id'] ?>" <?= (int) $f['category_id'] === (int) $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?= fieldError($errors, 'category_id') ?>
    </div>

    <div class="form-group">
      <label for="description">What do you need done?</label>
      <textarea id="description" name="description" maxlength="2000" class="<?= $c('description') ?>" required placeholder="e.g. The kitchen tap has been leaking since yesterday and needs replacing."><?= e($f['description']) ?></textarea>
      <?= fieldError($errors, 'description') ?>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label for="min_budget">Minimum budget (Rs)</label>
        <input type="number" id="min_budget" name="min_budget" min="1" max="99999.99" step="0.01" class="<?= $c('min_budget') ?>" value="<?= e($f['min_budget']) ?>" required>
        <?= fieldError($errors, 'min_budget') ?>
      </div>
      <div class="form-group">
        <label for="max_budget">Maximum budget (Rs)</label>
        <input type="number" id="max_budget" name="max_budget" min="1" max="99999.99" step="0.01" class="<?= $c('max_budget') ?>" value="<?= e($f['max_budget']) ?>" required>
        <?= fieldError($errors, 'max_budget') ?>
      </div>
    </div>

    <div class="form-group">
      <label for="urgency">Needed by</label>
      <input type="datetime-local" id="urgency" name="urgency" min="<?= e(date('Y-m-d\TH:i')) ?>" class="<?= $c('urgency') ?>" value="<?= e($f['urgency']) ?>" required>
      <p class="form-hint">Your request stays open for offers until this time.</p>
      <?= fieldError($errors, 'urgency') ?>
    </div>

    <div class="form-group">
      <label for="location">Location</label>
      <textarea id="location" name="location" maxlength="500" style="min-height:80px" class="<?= $c('location') ?>" required placeholder="House number, street, area, city"><?= e($f['location']) ?></textarea>
      <p class="form-hint">Include your area name (e.g. Gulberg). Providers are matched using it.</p>
      <?= fieldError($errors, 'location') ?>
    </div>

    <div class="form-group">
      <label for="images">Photos <span class="muted">(optional)</span></label>
      <input type="file" id="images" name="images[]" multiple accept="image/jpeg,image/png,image/webp" class="<?= $c('images') ?>">
      <p class="form-hint">Up to 5 images (JPG, PNG or WebP), 2 MB each. Choose them again if the form shows an error.</p>
      <?= fieldError($errors, 'images') ?>
    </div>

    <button type="submit" class="btn btn-primary btn-lg">Post request</button>
    <a class="btn btn-outline btn-lg" href="/customer/dashboard.php">Cancel</a>
  </form>
  <?php endif; ?>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
