<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('C');

$user = currentUser();
$uid  = (int) $user['id'];

$stmt = db()->prepare(
    "SELECT
       (SELECT COUNT(*) FROM service_requests WHERE customer_id = ? AND status = 'O') AS open_requests,
       (SELECT COUNT(*) FROM offers o JOIN service_requests r ON r.id = o.service_request_id
         WHERE r.customer_id = ? AND r.status = 'O' AND o.status = 'P') AS pending_offers,
       (SELECT COUNT(*) FROM bookings b JOIN offers o ON o.id = b.offer_id
          JOIN service_requests r ON r.id = o.service_request_id
         WHERE r.customer_id = ? AND b.status = 'CONF') AS active_bookings,
       (SELECT COUNT(*) FROM bookings b JOIN offers o ON o.id = b.offer_id
          JOIN service_requests r ON r.id = o.service_request_id
         WHERE r.customer_id = ? AND b.status = 'COMP') AS completed_bookings"
);
$stmt->execute([$uid, $uid, $uid, $uid]);
$stats = $stmt->fetch();

$stmt = db()->prepare(
    'SELECT r.id, r.description, r.status, r.urgency, c.name AS category_name,
            (SELECT COUNT(*) FROM offers o WHERE o.service_request_id = r.id) AS offer_count
     FROM service_requests r JOIN service_categories c ON c.id = r.category_id
     WHERE r.customer_id = ? ORDER BY r.id DESC LIMIT 5'
);
$stmt->execute([$uid]);
$recent = $stmt->fetchAll();

$pageTitle = 'Customer dashboard';
require_once BASE_PATH . '/includes/header.php';
$customerNavActive = 'dashboard';
require BASE_PATH . '/includes/customer_nav.php';
?>
<div class="container section">
  <div class="page-head">
    <h1>Hello, <?= e($user['first_name'] !== '' ? $user['first_name'] : $user['username']) ?></h1>
    <a class="btn btn-primary" href="/customer/request_new.php">+ New request</a>
  </div>

  <div class="stat-row">
    <?php foreach (['open_requests' => 'Open requests', 'pending_offers' => 'Offers waiting for you',
                    'active_bookings' => 'Active bookings', 'completed_bookings' => 'Completed jobs'] as $k => $label): ?>
      <div class="card stat-card"><div class="stat-value"><?= (int) $stats[$k] ?></div><div class="stat-label"><?= e($label) ?></div></div>
    <?php endforeach; ?>
  </div>

  <?php if ((int) $stats['pending_offers'] > 0): ?>
    <div class="alert alert-info">You have offers waiting. <a href="/customer/requests.php?status=O"><strong>Review your open requests</strong></a></div>
  <?php endif; ?>

  <h2>Recent requests</h2>
  <?php if (!$recent): ?>
    <div class="card empty-state">
      <h3>You haven't posted a request yet</h3>
      <p>Describe what you need and providers in your area will send you offers.</p>
      <a class="btn btn-primary" href="/customer/request_new.php">Post your first request</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Category</th><th>Description</th><th>Needed by</th><th>Offers</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td><strong><?= e($r['category_name']) ?></strong></td>
            <td><?= e(snippet($r['description'], 70)) ?></td>
            <td><?= e(formatDateTime($r['urgency'])) ?></td>
            <td><?= (int) $r['offer_count'] ?></td>
            <td><?= requestStatusBadge($r['status']) ?></td>
            <td><a class="btn btn-outline btn-sm" href="/customer/request.php?id=<?= (int) $r['id'] ?>">View</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p style="margin-top:1rem"><a href="/customer/requests.php">See all requests</a></p>
  <?php endif; ?>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
