<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once BASE_PATH . '/app/reviews.php';
requireRole('C');

$user = currentUser();
$stmt = db()->prepare(
    'SELECT rv.id, rv.rating, rv.text, rv.created_at, b.id AS booking_id, c.name AS category_name,
            pu.first_name, pu.last_name, pu.username
     FROM reviews rv
     JOIN bookings b ON b.id = rv.booking_id
     JOIN offers o ON o.id = b.offer_id
     JOIN service_requests r ON r.id = o.service_request_id
     JOIN service_categories c ON c.id = r.category_id
     JOIN provider_profiles pp ON pp.id = o.service_provider_id
     JOIN users pu ON pu.id = pp.user_id
     WHERE r.customer_id = ?
     ORDER BY rv.id DESC'
);
$stmt->execute([$user['id']]);
$reviews = $stmt->fetchAll();
$imageMap = reviewImagesMap(array_map('intval', array_column($reviews, 'id')));

// Completed bookings still waiting for a review
$stmt = db()->prepare(
    "SELECT b.id, b.scheduled_time, c.name AS category_name
     FROM bookings b
     JOIN offers o ON o.id = b.offer_id
     JOIN service_requests r ON r.id = o.service_request_id
     JOIN service_categories c ON c.id = r.category_id
     LEFT JOIN reviews rv ON rv.booking_id = b.id
     WHERE r.customer_id = ? AND b.status = 'COMP' AND rv.id IS NULL
     ORDER BY b.id DESC"
);
$stmt->execute([$user['id']]);
$waiting = $stmt->fetchAll();

$pageTitle = 'My reviews';
require_once BASE_PATH . '/includes/header.php';
$customerNavActive = 'reviews';
require BASE_PATH . '/includes/customer_nav.php';
?>
<div class="container section">
  <h1>My reviews</h1>

  <?php if ($waiting): ?>
    <div class="alert alert-info">
      <strong>Waiting for your review:</strong>
      <?php foreach ($waiting as $w): ?>
        <a href="/customer/review.php?booking=<?= (int) $w['id'] ?>" style="margin-left:.75rem"><?= e($w['category_name']) ?> (<?= e(formatDateTime($w['scheduled_time'])) ?>)</a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$reviews): ?>
    <div class="card empty-state"><h3>No reviews yet</h3><p>After a booking is completed you can rate the provider here.</p></div>
  <?php endif; ?>

  <?php foreach ($reviews as $r): ?>
    <div class="card review-card mb-1">
      <div class="card-head">
        <div><strong><?= e($r['category_name']) ?></strong> · <?= e(userDisplayName($r)) ?><br>
             <small class="muted"><?= e(formatDateTime($r['created_at'])) ?></small></div>
        <div><?= starString((int) $r['rating']) ?></div>
      </div>
      <p class="pre-wrap"><?= e($r['text']) ?></p>
      <?= gallery($imageMap[(int) $r['id']] ?? []) ?>
      <a href="/booking.php?id=<?= (int) $r['booking_id'] ?>">View booking</a>
    </div>
  <?php endforeach; ?>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
