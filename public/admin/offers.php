<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
requireAdmin();

$tabs = ['all' => 'All', 'P' => 'Pending', 'A' => 'Accepted', 'R' => 'Rejected', 'E' => 'Expired'];
$status = isset($tabs[$_GET['status'] ?? '']) ? $_GET['status'] : 'all';

$sql = 'SELECT o.id, o.price, o.arrival_time, o.status, o.created_at, r.id AS request_id, c.name AS category_name,
               cu.first_name AS cu_first, cu.last_name AS cu_last, cu.username AS cu_username,
               pu.first_name AS pu_first, pu.last_name AS pu_last, pu.username AS pu_username,
               b.id AS booking_id, b.status AS booking_status
        FROM offers o
        JOIN service_requests r ON r.id = o.service_request_id
        JOIN service_categories c ON c.id = r.category_id
        JOIN users cu ON cu.id = r.customer_id
        JOIN provider_profiles pp ON pp.id = o.service_provider_id
        JOIN users pu ON pu.id = pp.user_id
        LEFT JOIN bookings b ON b.offer_id = o.id';
$params = [];
if ($status !== 'all') { $sql .= ' WHERE o.status = ?'; $params[] = $status; }
$sql .= ' ORDER BY o.id DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$offers = $stmt->fetchAll();

$pageTitle   = 'Offers';
$adminActive = 'offers';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/admin_start.php';
?>
<div class="page-head"><h1>Offers</h1></div>
<div class="pill-tabs">
  <?php foreach ($tabs as $k => $label): ?>
    <a href="/admin/offers.php?status=<?= e($k) ?>"<?= $status === $k ? ' class="active"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$offers): ?>
  <div class="card empty-state"><h3>No offers here</h3></div>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th>Request</th><th>Customer</th><th>Provider</th><th>Price</th><th>Arrival</th><th>Sent</th><th>Offer</th><th>Booking</th></tr></thead>
    <tbody>
    <?php foreach ($offers as $o): ?>
      <tr>
        <td><?= (int) $o['id'] ?></td>
        <td><strong><?= e($o['category_name']) ?></strong> <small class="muted">#<?= (int) $o['request_id'] ?></small></td>
        <td><?= e(userDisplayName(['first_name' => $o['cu_first'], 'last_name' => $o['cu_last'], 'username' => $o['cu_username']])) ?></td>
        <td><?= e(userDisplayName(['first_name' => $o['pu_first'], 'last_name' => $o['pu_last'], 'username' => $o['pu_username']])) ?></td>
        <td><?= e(money($o['price'])) ?></td>
        <td><?= e(formatDateTime($o['arrival_time'])) ?></td>
        <td><?= e(formatDateTime($o['created_at'])) ?></td>
        <td><?= offerStatusBadge($o['status']) ?></td>
        <td><?= $o['booking_id'] ? bookingStatusBadge($o['booking_status']) . ' <small class="muted">#' . (int) $o['booking_id'] . '</small>' : '<span class="muted">-</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($offers) === 200): ?><p class="muted" style="margin-top:.75rem">Showing the 200 most recent.</p><?php endif; ?>
<?php endif; ?>
<?php
require_once BASE_PATH . '/includes/admin_end.php';
require_once BASE_PATH . '/includes/footer.php';
