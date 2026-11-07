<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once BASE_PATH . '/app/requests.php';
requireAdmin();

$tabs = ['all' => 'All', 'O' => 'Open', 'C' => 'Closed', 'E' => 'Expired'];

if (isPost()) {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);
    $back   = isset($tabs[$_POST['filter'] ?? '']) ? $_POST['filter'] : 'all';

    try {
        if ($action === 'expire') {
            flash(expireRequests($id) ? 'success' : 'error',
                  'Request expired. Its pending offers were expired too.');
        } elseif ($action === 'expire_overdue') {
            $n = expireRequests();
            flash('success', $n > 0 ? "$n overdue request(s) expired." : 'No overdue requests to expire.');
        } elseif ($action === 'delete') {
            $files = filesForRequestIds([$id]);
            $stmt = db()->prepare('DELETE FROM service_requests WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount()) { deleteFiles($files); flash('success', 'Request deleted.'); }
            else { flash('error', 'That request no longer exists.'); }
        }
    } catch (Throwable $ex) {
        error_log('Admin request action failed: ' . $ex->getMessage());
        flash('error', 'Something went wrong. Nothing was changed.');
    }
    redirect('/admin/requests.php?status=' . $back);
}

$status = isset($tabs[$_GET['status'] ?? '']) ? $_GET['status'] : 'all';
$counts = db()->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(status='O'),0) AS o, COALESCE(SUM(status='C'),0) AS c, COALESCE(SUM(status='E'),0) AS e,
            COALESCE(SUM(status='O' AND urgency <= NOW()),0) AS overdue
     FROM service_requests"
)->fetch();
$countFor = ['all' => $counts['total'], 'O' => $counts['o'], 'C' => $counts['c'], 'E' => $counts['e']];

$sql = 'SELECT r.id, r.description, r.min_budget, r.max_budget, r.urgency, r.status, r.location, r.created_at,
               c.name AS category_name, u.first_name, u.last_name, u.username, u.email,
               (SELECT COUNT(*) FROM offers o WHERE o.service_request_id = r.id) AS offer_count
        FROM service_requests r
        JOIN service_categories c ON c.id = r.category_id
        JOIN users u ON u.id = r.customer_id';
$params = [];
if ($status !== 'all') { $sql .= ' WHERE r.status = ?'; $params[] = $status; }
$sql .= ' ORDER BY r.id DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pageTitle   = 'Requests';
$adminActive = 'requests';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/admin_start.php';
?>
<div class="page-head">
  <h1>Service requests</h1>
  <?php if ((int) $counts['overdue'] > 0): ?>
    <form method="post" data-confirm="Expire all <?= (int) $counts['overdue'] ?> open request(s) that are past their deadline? Their pending offers will be expired too.">
      <?= csrfField() ?><input type="hidden" name="action" value="expire_overdue"><input type="hidden" name="filter" value="<?= e($status) ?>">
      <button type="submit" class="btn btn-accent">Expire <?= (int) $counts['overdue'] ?> overdue request<?= (int) $counts['overdue'] === 1 ? '' : 's' ?></button>
    </form>
  <?php endif; ?>
</div>

<div class="pill-tabs">
  <?php foreach ($tabs as $k => $label): ?>
    <a href="/admin/requests.php?status=<?= e($k) ?>"<?= $status === $k ? ' class="active"' : '' ?>><?= e($label) ?> (<?= (int) $countFor[$k] ?>)</a>
  <?php endforeach; ?>
</div>

<?php if (!$requests): ?>
  <div class="card empty-state"><h3>No requests here</h3></div>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th>Category / Customer</th><th>Description</th><th>Budget</th><th>Deadline</th><th>Offers</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($requests as $r): $overdue = $r['status'] === 'O' && strtotime($r['urgency']) <= time(); ?>
      <tr>
        <td><?= (int) $r['id'] ?></td>
        <td><strong><?= e($r['category_name']) ?></strong><br><small class="muted"><?= e(userDisplayName($r)) ?> · <?= e($r['email']) ?></small></td>
        <td><?= e(snippet($r['description'], 70)) ?><br><small class="muted"><?= icon('pin') ?> <?= e(snippet($r['location'], 40)) ?></small></td>
        <td><?= e(money($r['min_budget'])) ?> – <?= e(money($r['max_budget'])) ?></td>
        <td><?= e(formatDateTime($r['urgency'])) ?><?= $overdue ? '<br>' . badge('Overdue', 'warning') : '' ?></td>
        <td><?= (int) $r['offer_count'] ?></td>
        <td><?= requestStatusBadge($r['status']) ?></td>
        <td><div class="row-actions">
          <?php if ($r['status'] === 'O'): ?>
            <form method="post" data-confirm="Expire this request? Providers will no longer see it and its pending offers will be expired.">
              <?= csrfField() ?><input type="hidden" name="action" value="expire"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="filter" value="<?= e($status) ?>">
              <button type="submit" class="btn btn-outline btn-sm">Expire</button>
            </form>
          <?php endif; ?>
          <form method="post" data-confirm="Permanently delete this request with all its offers, bookings, reviews and images? This cannot be undone.">
            <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="filter" value="<?= e($status) ?>">
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
          </form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($requests) === 200): ?><p class="muted" style="margin-top:.75rem">Showing the 200 most recent.</p><?php endif; ?>
<?php endif; ?>
<?php
require_once BASE_PATH . '/includes/admin_end.php';
require_once BASE_PATH . '/includes/footer.php';
