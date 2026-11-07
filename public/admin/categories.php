<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once BASE_PATH . '/app/requests.php';
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
            $stmt = db()->prepare('SELECT id FROM service_categories WHERE name = ? AND id <> ?');
            $stmt->execute([$name, $id]);
            if ($stmt->fetch()) {
                $errors['name'] = 'A category with this name already exists.';
            }
        }

        if ($id > 0) {                                   // editing: the row must exist
            $stmt = db()->prepare('SELECT id FROM service_categories WHERE id = ?');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                flash('error', 'That category no longer exists.');
                redirect('/admin/categories.php');
            }
        }

        if (!$errors) {
            try {
                if ($id > 0) {
                    db()->prepare('UPDATE service_categories SET name = ? WHERE id = ?')->execute([$name, $id]);
                    flash('success', 'Category updated.');
                } else {
                    db()->prepare('INSERT INTO service_categories (name) VALUES (?)')->execute([$name]);
                    flash('success', 'Category added.');
                }
                redirect('/admin/categories.php');
            } catch (PDOException $ex) {
                $errors['name'] = $ex->getCode() === '23000'
                    ? 'A category with this name already exists.'
                    : 'Could not save the category. Please try again.';
            }
        }
        if ($id > 0) {                                   // stay in edit mode and keep the typed text
            $editRow = ['id' => $id, 'name' => $name];
        }

    } elseif ($action === 'delete') {
        $files = filesForCategory($id);          // collect image files before the cascade removes the rows
        $stmt = db()->prepare('DELETE FROM service_categories WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount()) { deleteFiles($files); }
        flash($stmt->rowCount() ? 'success' : 'error',
              $stmt->rowCount() ? 'Category deleted.' : 'That category no longer exists.');
        redirect('/admin/categories.php');
    }
}

if (!$editRow && isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT id, name FROM service_categories WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editRow = $stmt->fetch() ?: null;
}

$categories = db()->query(
    'SELECT c.id, c.name, c.created_at,
            (SELECT COUNT(*) FROM services s WHERE s.category_id = c.id)          AS service_count,
            (SELECT COUNT(*) FROM service_requests r WHERE r.category_id = c.id) AS request_count
     FROM service_categories c ORDER BY c.id'
)->fetchAll();

$pageTitle   = 'Categories';
$adminActive = 'categories';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/admin_start.php';
?>
<div class="page-head"><h1>Service categories</h1></div>

<div class="card mb-1">
  <h3><?= $editRow ? 'Edit category' : 'Add a category' ?></h3>
  <form method="post" class="inline-form" novalidate>
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editRow ? (int) $editRow['id'] : 0 ?>">
    <div class="form-group">
      <label for="name">Category name</label>
      <input type="text" id="name" name="name" maxlength="50" required
             class="<?= isset($errors['name']) ? 'input-error' : '' ?>"
             value="<?= e($editRow['name'] ?? '') ?>" placeholder="e.g. Plumbing">
      <?= fieldError($errors, 'name') ?>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><?= $editRow ? 'Save changes' : 'Add category' ?></button>
      <?php if ($editRow): ?><a class="btn btn-outline" href="/admin/categories.php">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<?php if (!$categories): ?>
  <div class="card empty-state"><h3>No categories yet</h3><p>Add your first category above (e.g. Plumbing).</p></div>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Name</th><th>Services</th><th>Requests</th><th>Created</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($categories as $c): ?>
        <tr>
          <td><?= (int) $c['id'] ?></td>
          <td><strong><?= e($c['name']) ?></strong></td>
          <td><?= (int) $c['service_count'] ?></td>
          <td><?= (int) $c['request_count'] ?></td>
          <td><?= e(formatDateTime($c['created_at'])) ?></td>
          <td>
            <div class="row-actions">
              <a class="btn btn-outline btn-sm" href="/admin/categories.php?edit=<?= (int) $c['id'] ?>">Edit</a>
              <form method="post" data-confirm="Delete <?= e($c['name']) ?>? This will also permanently delete its <?= (int) $c['service_count'] ?> service(s) and <?= (int) $c['request_count'] ?> request(s), including their offers, bookings and reviews.">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
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
