<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
requireAdmin();

$errors  = [];
$editRow = null;
$categories = db()->query('SELECT id, name FROM service_categories ORDER BY name')->fetchAll();

if (isPost()) {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'save') {
        $name       = trim((string) ($_POST['name'] ?? ''));
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $len        = mb_strlen($name);

        if ($len < 2 || $len > 50) {
            $errors['name'] = 'Name must be between 2 and 50 characters.';
        } else {
            $stmt = db()->prepare('SELECT id FROM services WHERE name = ? AND id <> ?');
            $stmt->execute([$name, $id]);
            if ($stmt->fetch()) {
                $errors['name'] = 'A service with this name already exists.';
            }
        }

        $stmt = db()->prepare('SELECT id FROM service_categories WHERE id = ?');
        $stmt->execute([$categoryId]);
        if (!$stmt->fetch()) {
            $errors['category_id'] = 'Choose a category.';
        }

        if ($id > 0) {
            $stmt = db()->prepare('SELECT id FROM services WHERE id = ?');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                flash('error', 'That service no longer exists.');
                redirect('/admin/services.php');
            }
        }

        if (!$errors) {
            try {
                if ($id > 0) {
                    db()->prepare('UPDATE services SET name = ?, category_id = ? WHERE id = ?')
                        ->execute([$name, $categoryId, $id]);
                    flash('success', 'Service updated.');
                } else {
                    db()->prepare('INSERT INTO services (name, category_id) VALUES (?, ?)')
                        ->execute([$name, $categoryId]);
                    flash('success', 'Service added.');
                }
                redirect('/admin/services.php');
            } catch (PDOException $ex) {
                $errors['name'] = $ex->getCode() === '23000'
                    ? 'A service with this name already exists.'
                    : 'Could not save the service. Please try again.';
            }
        }
        if ($id > 0) {
            $editRow = ['id' => $id, 'name' => $name, 'category_id' => $categoryId];
        } else {
            $editRow = null;
        }

    } elseif ($action === 'delete') {
        $stmt = db()->prepare('DELETE FROM services WHERE id = ?');
        $stmt->execute([$id]);
        flash($stmt->rowCount() ? 'success' : 'error',
              $stmt->rowCount() ? 'Service deleted.' : 'That service no longer exists.');
        redirect('/admin/services.php');
    }
}

if (!$editRow && isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT id, name, category_id FROM services WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editRow = $stmt->fetch() ?: null;
}

// Keep the selected category in the dropdown after a failed "add"
$selectedCategory = (int) ($_POST['category_id'] ?? ($editRow['category_id'] ?? 0));

$services = db()->query(
    'SELECT s.id, s.name, s.created_at, c.name AS category_name,
            (SELECT COUNT(*) FROM provider_profile_services p WHERE p.service_id = s.id) AS provider_count
     FROM services s
     JOIN service_categories c ON c.id = s.category_id
     ORDER BY c.name, s.name'
)->fetchAll();

$pageTitle   = 'Services';
$adminActive = 'services';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/admin_start.php';
?>
<div class="page-head"><h1>Services</h1></div>

<?php if (!$categories): ?>
  <div class="card empty-state">
    <h3>Add a category first</h3>
    <p>Every service belongs to a category.</p>
    <a class="btn btn-primary" href="/admin/categories.php">Go to categories</a>
  </div>
<?php else: ?>
  <div class="card mb-1">
    <h3><?= $editRow ? 'Edit service' : 'Add a service' ?></h3>
    <form method="post" class="inline-form" novalidate>
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $editRow ? (int) $editRow['id'] : 0 ?>">
      <div class="form-group">
        <label for="name">Service name</label>
        <input type="text" id="name" name="name" maxlength="50" required
               class="<?= isset($errors['name']) ? 'input-error' : '' ?>"
               value="<?= e($editRow['name'] ?? ($_POST['name'] ?? '')) ?>" placeholder="e.g. Leak repair">
        <?= fieldError($errors, 'name') ?>
      </div>
      <div class="form-group">
        <label for="category_id">Category</label>
        <select id="category_id" name="category_id" required class="<?= isset($errors['category_id']) ? 'input-error' : '' ?>">
          <option value="">Choose…</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $selectedCategory === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= fieldError($errors, 'category_id') ?>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $editRow ? 'Save changes' : 'Add service' ?></button>
        <?php if ($editRow): ?><a class="btn btn-outline" href="/admin/services.php">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>

  <?php if (!$services): ?>
    <div class="card empty-state"><h3>No services yet</h3><p>Add your first service above.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Service</th><th>Category</th><th>Providers</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($services as $s): ?>
          <tr>
            <td><?= (int) $s['id'] ?></td>
            <td><strong><?= e($s['name']) ?></strong></td>
            <td><?= e($s['category_name']) ?></td>
            <td><?= (int) $s['provider_count'] ?></td>
            <td><?= e(formatDateTime($s['created_at'])) ?></td>
            <td>
              <div class="row-actions">
                <a class="btn btn-outline btn-sm" href="/admin/services.php?edit=<?= (int) $s['id'] ?>">Edit</a>
                <form method="post" data-confirm="Delete <?= e($s['name']) ?>? It will be removed from <?= (int) $s['provider_count'] ?> provider profile(s).">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php
require_once BASE_PATH . '/includes/admin_end.php';
require_once BASE_PATH . '/includes/footer.php';
