<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once BASE_PATH . '/app/providers.php';
$profile = requireProviderProfile();

$tabs = ['all' => 'All', 'P' => 'Pending', 'A' => 'Accepted', 'R' => 'Rejected', 'E' => 'Expired'];
$status = isset($tabs[$_GET['status'] ?? '']) ? $_GET['status'] : 'all';

$sql = 'SELECT o.id, o.price, o.arrival_time, o.status, o.created_at, r.id AS request_id, r.location,
               c.name AS category_name, b.id AS booking_id, b.status AS booking_status
        FROM offers o
        JOIN service_requests r ON r.id = o.service_request_id
        JOIN service_categories c ON c.id = r.category_id
        LEFT JOIN bookings b ON b.offer_id = o.id
        WHERE o.service_provider_id = ?';
$params = [$profile['id']];
if ($status !== 'all') { $sql .= ' AND o.status = ?'; $params[] = $status; }
$sql .= ' ORDER BY o.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$offers = $stmt->fetchAll();

$pageTitle = 'My offers';
require_once BASE_PATH . '/includes/header.php';
$providerNavActive = 'offers';
require BASE_PATH . '/includes/provider_nav.php';
?>
<div class="container section">
  <h1>My offers</h1>
  <div class="pill-tabs">
    <?php foreach ($tabs as $k => $label): ?>
      <a href="/provider/offers.php?status=<?= e($k) ?>"<?= $status === $k ? ' class="active"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$offers): ?>
    <div class="card empty-state"><h3>No offers here</h3><p>Offers you send on matching requests are tracked on this page.</p>
      <a class="btn btn-primary" href="/provider/requests.php">Find requests</a></div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Request</th><th>Location</th><th>My price</th><th>Arrival</th><th>Offer</th><th>Booking</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($offers as $o): ?>
        <tr>
          <td><strong><?= e($o['category_name']) ?></strong></td>
          <td><?= e(snippet($o['location'], 40)) ?></td>
          <td><?= e(money($o['price'])) ?></td>
          <td><?= e(formatDateTime($o['arrival_time'])) ?></td>
          <td><?= offerStatusBadge($o['status']) ?></td>
          <td><?= $o['booking_id'] ? bookingStatusBadge($o['booking_status']) : '<span class="muted">-</span>' ?></td>
          <td>
            <?php if ($o['booking_id']): ?><a class="btn btn-primary btn-sm" href="/booking.php?id=<?= (int) $o['booking_id'] ?>">Booking</a>
            <?php else: ?><a class="btn btn-outline btn-sm" href="/provider/request.php?id=<?= (int) $o['request_id'] ?>">Request</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
