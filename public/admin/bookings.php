<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
requireAdmin();

$tabs = ['all' => 'All', 'CONF' => 'Confirmed', 'COMP' => 'Completed', 'CANC' => 'Cancelled'];
$status = isset($tabs[$_GET['status'] ?? '']) ? $_GET['status'] : 'all';

$sql = 'SELECT b.id, b.scheduled_time, b.final_price, b.status, b.created_at, b.cancellation_reason,
               c.name AS category_name, r.id AS request_id, r.location,
               cu.first_name AS cu_first, cu.last_name AS cu_last, cu.username AS cu_username,
               pu.first_name AS pu_first, pu.last_name AS pu_last, pu.username AS pu_username,
               rv.rating
        FROM bookings b
        JOIN offers o ON o.id = b.offer_id
        JOIN service_requests r ON r.id = o.service_request_id
        JOIN service_categories c ON c.id = r.category_id
        JOIN users cu ON cu.id = r.customer_id
        JOIN provider_profiles pp ON pp.id = o.service_provider_id
        JOIN users pu ON pu.id = pp.user_id
        LEFT JOIN reviews rv ON rv.booking_id = b.id';
$params = [];
if ($status !== 'all') { $sql .= ' WHERE b.status = ?'; $params[] = $status; }
$sql .= ' ORDER BY b.id DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

$pageTitle   = 'Bookings';
$adminActive = 'bookings';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/admin_start.php';
?>
<div class="page-head"><h1>Bookings</h1></div>
<div class="pill-tabs">
  <?php foreach ($tabs as $k => $label): ?>
    <a href="/admin/bookings.php?status=<?= e($k) ?>"<?= $status === $k ? ' class="active"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$bookings): ?>
  <div class="card empty-state"><h3>No bookings here</h3></div>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th>Service</th><th>Customer</th><th>Provider</th><th>Scheduled</th><th>Price</th><th>Status</th><th>Review</th></tr></thead>
    <tbody>
    <?php foreach ($bookings as $b): ?>
      <tr>
        <td><?= (int) $b['id'] ?></td>
        <td><strong><?= e($b['category_name']) ?></strong><br><small class="muted"><?= icon('pin') ?> <?= e(snippet($b['location'], 40)) ?></small></td>
        <td><?= e(userDisplayName(['first_name' => $b['cu_first'], 'last_name' => $b['cu_last'], 'username' => $b['cu_username']])) ?></td>
        <td><?= e(userDisplayName(['first_name' => $b['pu_first'], 'last_name' => $b['pu_last'], 'username' => $b['pu_username']])) ?></td>
        <td><?= e(formatDateTime($b['scheduled_time'])) ?></td>
        <td><?= e(money($b['final_price'])) ?></td>
        <td><?= bookingStatusBadge($b['status']) ?>
            <?php if ($b['status'] === 'CANC' && $b['cancellation_reason'] !== ''): ?><br><small class="muted">Reason: <?= e(snippet($b['cancellation_reason'], 60)) ?></small><?php endif; ?></td>
        <td><?= $b['rating'] !== null ? str_repeat('★', (int) $b['rating']) : '<span class="muted">-</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($bookings) === 200): ?><p class="muted" style="margin-top:.75rem">Showing the 200 most recent.</p><?php endif; ?>
<?php endif; ?>
<?php
require_once BASE_PATH . '/includes/admin_end.php';
require_once BASE_PATH . '/includes/footer.php';
