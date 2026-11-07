<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requireLogin();
if (isAdmin()) { forbidden('Administrators manage bookings from the admin panel.'); }

$user = currentUser();
$uid  = (int) $user['id'];
$isProvider = $user['role'] === 'P';

$tabs = ['all' => 'All', 'CONF' => 'Confirmed', 'COMP' => 'Completed', 'CANC' => 'Cancelled'];
$status = isset($tabs[$_GET['status'] ?? '']) ? $_GET['status'] : 'all';

$sql = 'SELECT b.id, b.scheduled_time, b.final_price, b.status, c.name AS category_name, r.location,
               cu.first_name AS cu_first, cu.last_name AS cu_last, cu.username AS cu_username,
               pu.first_name AS pu_first, pu.last_name AS pu_last, pu.username AS pu_username,
               rv.id AS review_id
        FROM bookings b
        JOIN offers o ON o.id = b.offer_id
        JOIN service_requests r ON r.id = o.service_request_id
        JOIN service_categories c ON c.id = r.category_id
        JOIN provider_profiles pp ON pp.id = o.service_provider_id
        JOIN users cu ON cu.id = r.customer_id
        JOIN users pu ON pu.id = pp.user_id
        LEFT JOIN reviews rv ON rv.booking_id = b.id
        WHERE (r.customer_id = ? OR pp.user_id = ?)';
$params = [$uid, $uid];
if ($status !== 'all') { $sql .= ' AND b.status = ?'; $params[] = $status; }
$sql .= ' ORDER BY b.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

$pageTitle = 'Bookings';
require_once BASE_PATH . '/includes/header.php';
$roleNavActive = 'bookings';
require BASE_PATH . '/includes/role_nav.php';
?>
<div class="container section">
  <h1><?= $isProvider ? 'Bookings' : 'My bookings' ?></h1>
  <div class="pill-tabs">
    <?php foreach ($tabs as $k => $label): ?>
      <a href="/bookings.php?status=<?= e($k) ?>"<?= $status === $k ? ' class="active"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$bookings): ?>
    <div class="card empty-state"><h3>No bookings here</h3>
      <p><?= $isProvider ? 'When a customer accepts one of your offers, the booking appears here.' : 'Accept an offer on one of your requests to create a booking.' ?></p></div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Service</th><th><?= $isProvider ? 'Customer' : 'Provider' ?></th><th>Scheduled</th><th>Price</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($bookings as $b):
          $other = $isProvider
              ? ['first_name' => $b['cu_first'], 'last_name' => $b['cu_last'], 'username' => $b['cu_username']]
              : ['first_name' => $b['pu_first'], 'last_name' => $b['pu_last'], 'username' => $b['pu_username']]; ?>
        <tr>
          <td><strong><?= e($b['category_name']) ?></strong><br><small class="muted"><?= e(snippet($b['location'], 40)) ?></small></td>
          <td><?= e(userDisplayName($other)) ?></td>
          <td><?= e(formatDateTime($b['scheduled_time'])) ?></td>
          <td><?= e(money($b['final_price'])) ?></td>
          <td><?= bookingStatusBadge($b['status']) ?><?= $b['review_id'] ? ' ' . badge('Reviewed', 'info') : '' ?></td>
          <td><a class="btn btn-outline btn-sm" href="/booking.php?id=<?= (int) $b['id'] ?>">Details</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
