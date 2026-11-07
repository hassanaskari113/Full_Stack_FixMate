<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once BASE_PATH . '/app/providers.php';
require_once BASE_PATH . '/app/matching.php';
require_once BASE_PATH . '/app/reviews.php';
requireRole('P');

$user    = currentUser();
$profile = getProviderProfile((int) $user['id']);
$stats = null;
$recentReviews = [];

if ($profile) {
    $pid      = (int) $profile['id'];
    $services = providerServiceList($pid);
    $areas    = providerAreaNames($pid);

    if (isApprovedProvider($profile)) {
        $stmt = db()->prepare(
            "SELECT
               (SELECT COUNT(*) FROM offers WHERE service_provider_id = :a1 AND status = 'P') AS pending_offers,
               (SELECT COUNT(*) FROM offers WHERE service_provider_id = :a2 AND status = 'A') AS accepted_offers,
               (SELECT COUNT(*) FROM bookings b JOIN offers o ON o.id = b.offer_id
                 WHERE o.service_provider_id = :a3 AND b.status = 'CONF') AS active_bookings,
               (SELECT COUNT(*) FROM bookings b JOIN offers o ON o.id = b.offer_id
                 WHERE o.service_provider_id = :a4 AND b.status = 'COMP') AS completed_jobs,
               (SELECT ROUND(AVG(rv.rating), 1) FROM reviews rv JOIN bookings b ON b.id = rv.booking_id
                  JOIN offers o ON o.id = b.offer_id WHERE o.service_provider_id = :a5) AS avg_rating,
               (SELECT COUNT(*) FROM reviews rv JOIN bookings b ON b.id = rv.booking_id
                  JOIN offers o ON o.id = b.offer_id WHERE o.service_provider_id = :a6) AS review_count"
        );
        $stmt->execute([':a1' => $pid, ':a2' => $pid, ':a3' => $pid, ':a4' => $pid, ':a5' => $pid, ':a6' => $pid]);
        $stats = $stmt->fetch();
        $stats['matching'] = count(array_filter(matchingRequests($pid), fn($r) => !$r['my_offer_id']));

        $stmt = db()->prepare(
            'SELECT rv.id, rv.rating, rv.text, rv.created_at, cu.first_name
             FROM reviews rv
             JOIN bookings b ON b.id = rv.booking_id
             JOIN offers o ON o.id = b.offer_id
             JOIN service_requests r ON r.id = o.service_request_id
             JOIN users cu ON cu.id = r.customer_id
             WHERE o.service_provider_id = ? ORDER BY rv.id DESC LIMIT 5'
        );
        $stmt->execute([$pid]);
        $recentReviews = $stmt->fetchAll();
    }
}

$pageTitle = 'Provider dashboard';
require_once BASE_PATH . '/includes/header.php';
$providerNavActive = 'dashboard';
require BASE_PATH . '/includes/provider_nav.php';
?>
<div class="container section">
  <h1>Hello, <?= e($user['first_name'] !== '' ? $user['first_name'] : $user['username']) ?></h1>

  <?php if (!$profile): ?>
    <div class="card empty-state">
      <h3>Complete your provider profile</h3>
      <p>Add your experience, services and service areas. Once an administrator approves your profile, you can start sending offers.</p>
      <a class="btn btn-primary btn-lg" href="/provider/profile.php">Set up my profile</a>
    </div>

  <?php else: ?>
    <?php $status = $profile['verification_status']; ?>

    <?php if ($status === 'P'): ?>
      <div class="alert alert-warning"><strong>Verification pending.</strong> An administrator is reviewing your profile. You'll be able to send offers once it is approved.</div>
    <?php elseif ($status === 'R'): ?>
      <div class="alert alert-error">
        <strong>Your profile was rejected.</strong> Please review your details and resubmit.
        <a class="btn btn-danger btn-sm" style="margin-left:.5rem" href="/provider/profile.php">Edit and resubmit</a>
      </div>
    <?php endif; ?>

    <?php if ($stats): ?>
      <div class="stat-row">
        <div class="card stat-card"><div class="stat-value"><?= (int) $stats['matching'] ?></div><div class="stat-label">New matching requests</div></div>
        <div class="card stat-card"><div class="stat-value"><?= (int) $stats['pending_offers'] ?></div><div class="stat-label">Offers pending</div></div>
        <div class="card stat-card"><div class="stat-value"><?= (int) $stats['active_bookings'] ?></div><div class="stat-label">Active bookings</div></div>
        <div class="card stat-card"><div class="stat-value"><?= (int) $stats['completed_jobs'] ?></div><div class="stat-label">Completed jobs</div></div>
        <div class="card stat-card"><div class="stat-value" style="font-size:1.3rem"><?= ratingStars($stats['avg_rating'], (int) $stats['review_count']) ?></div><div class="stat-label">Your rating</div></div>
      </div>
      <?php if ((int) $stats['matching'] > 0): ?>
        <div class="alert alert-info"><?= (int) $stats['matching'] ?> request<?= (int) $stats['matching'] === 1 ? '' : 's' ?> match your services and areas. <a href="/provider/requests.php"><strong>View and send offers</strong></a></div>
      <?php endif; ?>
    <?php endif; ?>

    <div class="card mb-1">
      <div class="card-head">
        <div class="person">
          <?= avatar($user) ?>
          <div class="person-info"><strong><?= e(userDisplayName($user)) ?></strong><small><?= e($user['email']) ?></small></div>
        </div>
        <div><?= verificationBadge($status) ?></div>
      </div>

      <div class="kv">
        <div><small>Experience</small><?= (int) $profile['experience'] ?> year<?= (int) $profile['experience'] === 1 ? '' : 's' ?></div>
        <div><small>Profile created</small><?= e(formatDateTime($profile['created_at'])) ?></div>
      </div>

      <?php if ($profile['bio'] !== ''): ?>
        <div class="label-sm">Bio</div><p class="pre-wrap"><?= e($profile['bio']) ?></p>
      <?php endif; ?>

      <div class="label-sm">Services</div>
      <div class="service-chips"><?php foreach ($services as $s): ?><span class="chip"><?= e($s['name']) ?></span><?php endforeach; ?></div>
      <div class="label-sm">Areas served</div>
      <div class="service-chips"><?php foreach ($areas as $a): ?><span class="chip"><?= e($a) ?></span><?php endforeach; ?></div>

      <p style="margin-top:1.5rem;margin-bottom:0"><a class="btn btn-outline" href="/provider/profile.php">Edit profile</a></p>
    </div>

    <?php if ($recentReviews): ?>
      <h2>Recent reviews</h2>
      <?php foreach ($recentReviews as $r): ?>
        <div class="card review-card mb-1">
          <div class="card-head"><strong><?= e($r['first_name']) ?></strong><?= starString((int) $r['rating']) ?></div>
          <p class="pre-wrap" style="margin:0"><?= e($r['text']) ?></p>
          <small class="muted"><?= e(formatDateTime($r['created_at'])) ?></small>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
