<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
requireAdmin();

$user = currentUser();
$s = db()->query(
    "SELECT
       (SELECT COUNT(*) FROM users WHERE is_admin = 0 AND role = 'C')            AS customers,
       (SELECT COUNT(*) FROM users WHERE is_admin = 0 AND role = 'P')            AS providers,
       (SELECT COUNT(*) FROM provider_profiles WHERE verification_status = 'P')  AS pending_providers,
       (SELECT COUNT(*) FROM service_categories)                                 AS categories,
       (SELECT COUNT(*) FROM services)                                           AS services,
       (SELECT COUNT(*) FROM service_areas)                                      AS areas,
       (SELECT COUNT(*) FROM service_requests WHERE status = 'O')                AS open_requests,
       (SELECT COUNT(*) FROM service_requests WHERE status = 'O' AND urgency <= NOW()) AS overdue_requests,
       (SELECT COUNT(*) FROM service_requests)                                   AS total_requests,
       (SELECT COUNT(*) FROM offers)                                             AS total_offers,
       (SELECT COUNT(*) FROM bookings WHERE status = 'CONF')                     AS active_bookings,
       (SELECT COUNT(*) FROM bookings WHERE status = 'COMP')                     AS completed_bookings,
       (SELECT COUNT(*) FROM bookings WHERE status = 'CANC')                     AS cancelled_bookings,
       (SELECT COUNT(*) FROM reviews)                                            AS total_reviews,
       (SELECT ROUND(AVG(rating), 1) FROM reviews)                               AS avg_rating"
)->fetch();

$pageTitle   = 'Admin dashboard';
$adminActive = 'dashboard';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/admin_start.php';
?>
<div class="page-head"><h1>Admin dashboard</h1></div>
<p class="muted">Signed in as <?= e($user['email']) ?>.</p>

<?php if ((int) $s['pending_providers'] > 0): ?>
  <div class="alert alert-warning"><?= (int) $s['pending_providers'] ?> provider profile<?= (int) $s['pending_providers'] === 1 ? ' is' : 's are' ?> waiting for verification.
    <a href="/admin/providers.php?status=P"><strong>Review now</strong></a></div>
<?php endif; ?>
<?php if ((int) $s['overdue_requests'] > 0): ?>
  <div class="alert alert-info"><?= (int) $s['overdue_requests'] ?> open request<?= (int) $s['overdue_requests'] === 1 ? ' is' : 's are' ?> past <?= (int) $s['overdue_requests'] === 1 ? 'its' : 'their' ?> deadline.
    <a href="/admin/requests.php?status=O"><strong>Manage requests</strong></a></div>
<?php endif; ?>

<?php
$groups = [
  'People' => ['customers' => 'Customers', 'providers' => 'Providers', 'pending_providers' => 'Pending verification'],
  'Catalogue' => ['categories' => 'Categories', 'services' => 'Services', 'areas' => 'Service areas'],
  'Marketplace' => ['open_requests' => 'Open requests', 'total_requests' => 'Total requests', 'total_offers' => 'Offers sent'],
  'Bookings' => ['active_bookings' => 'Confirmed', 'completed_bookings' => 'Completed', 'cancelled_bookings' => 'Cancelled'],
  'Reviews' => ['total_reviews' => 'Reviews', 'avg_rating' => 'Average rating'],
];
foreach ($groups as $title => $items): ?>
  <div class="label-sm"><?= e($title) ?></div>
  <div class="stat-row" style="margin-top:.25rem">
    <?php foreach ($items as $key => $label): ?>
      <div class="card stat-card">
        <div class="stat-value"><?= $key === 'avg_rating' ? ($s[$key] !== null ? e($s[$key]) . ' ★' : '-') : (int) $s[$key] ?></div>
        <div class="stat-label"><?= e($label) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php
require_once BASE_PATH . '/includes/admin_end.php';
require_once BASE_PATH . '/includes/footer.php';
