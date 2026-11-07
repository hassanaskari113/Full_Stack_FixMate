<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
requireAdmin();

$errors  = [];
$editRow = null;

if (isPost()) {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'save') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $len  = mb_strlen($name);

        if ($len < 2 || $len > 50) {
            $errors['name'] = 'Name must be between 2 and 50 characters.';
        } else {
            $stmt = db()->prepare('SELECT id FROM service_areas WHERE name = ? AND id <> ?');
            $stmt->execute([$name, $id]);
            if ($stmt->fetch()) {
                $errors['name'] = 'A service area with this name already exists.';
            }
        }

        if ($id > 0) {
            $stmt = db()->prepare('SELECT id FROM service_areas WHERE id = ?');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                flash('error', 'That area no longer exists.');
                redirect('/admin/areas.php');
            }
        }

        if (!$errors) {
            try {
                if ($id > 0) {
                    db()->prepare('UPDATE service_areas SET name = ? WHERE id = ?')->execute([$name, $id]);
                    flash('success', 'Service area updated.');
                } else {
                    db()->prepare('INSERT INTO service_areas (name) VALUES (?)')->execute([$name]);
                    flash('success', 'Service area added.');
                }
                redirect('/admin/areas.php');
            } catch (PDOException $ex) {
                $errors['name'] = $ex->getCode() === '23000'
                    ? 'A service area with this name already exists.'
                    : 'Could not save the area. Please try again.';
            }
        }
        if ($id > 0) {
            $editRow = ['id' => $id, 'name' => $name];
        }

    } elseif ($action === 'delete') {
        $stmt = db()->prepare('DELETE FROM service_areas WHERE id = ?');
        $stmt->execute([$id]);
        flash($stmt->rowCount() ? 'success' : 'error',
              $stmt->rowCount() ? 'Service area deleted.' : 'That area no longer exists.');
        redirect('/admin/areas.php');
    }
}

if (!$editRow && isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT id, name FROM service_areas WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editRow = $stmt->fetch() ?: null;
}

$areas = db()->query(
    'SELECT a.id, a.name, a.created_at,
            (SELECT COUNT(*) FROM provider_profile_service_areas p WHERE p.service_area_id = a.id) AS provider_count
     FROM service_areas a ORDER BY a.id'
)->fetchAll();

$pageTitle   = 'Service areas';
$adminActive = 'areas';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/admin_start.php';
?>
<div class="page-head"><h1>Service areas</h1></div>

<div class="card mb-1">
  <h3><?= $editRow ? 'Edit service area' : 'Add a service area' ?></h3>
  <p class="muted">Requests are matched to providers when the request location contains the area name, so use names people actually write (e.g. "Gulberg").</p>
  <form method="post" class="inline-form" novalidate>
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editRow ? (int) $editRow['id'] : 0 ?>">
    <div class="form-group">
      <label for="name">Area name</label>
      <input type="text" id="name" name="name" maxlength="50" required
             class="<?= isset($errors['name']) ? 'input-error' : '' ?>"
             value="<?= e($editRow['name'] ?? '') ?>" placeholder="e.g. Gulberg">
      <?= fieldError($errors, 'name') ?>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><?= $editRow ? 'Save changes' : 'Add area' ?></button>
      <?php if ($editRow): ?><a class="btn btn-outline" href="/admin/areas.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<?php if (!$areas): ?>
  <div class="card empty-state"><h3>No service areas yet</h3><p>Add the areas where providers can work.</p></div>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Name</th><th>Providers</th><th>Created</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($areas as $a): ?>
        <tr>
          <td><?= (int) $a['id'] ?></td>
          <td><strong><?= e($a['name']) ?></strong></td>
          <td><?= (int) $a['provider_count'] ?></td>
          <td><?= e(formatDateTime($a['created_at'])) ?></td>
          <td>
            <div class="row-actions">
              <a class="btn btn-outline btn-sm" href="/admin/areas.php?edit=<?= (int) $a['id'] ?>">Edit</a>
              <form method="post" data-confirm="Delete <?= e($a['name']) ?>? It will be removed from <?= (int) $a['provider_count'] ?> provider profile(s).">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
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
<?php
require_once BASE_PATH . '/includes/admin_end.php';
require_once BASE_PATH . '/includes/footer.php';
