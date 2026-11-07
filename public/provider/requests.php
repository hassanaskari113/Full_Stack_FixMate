<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once BASE_PATH . '/app/providers.php';
require_once BASE_PATH . '/app/matching.php';
$profile  = requireApprovedProvider();
$requests = matchingRequests((int) $profile['id']);

$pageTitle = 'Matching requests';
require_once BASE_PATH . '/includes/header.php';
$providerNavActive = 'requests';
require BASE_PATH . '/includes/provider_nav.php';
?>
<div class="container section">
  <h1>Matching requests</h1>
  <p class="muted">Open requests in your service categories and areas, soonest deadline first.</p>

  <?php if (!$requests): ?>
    <div class="card empty-state">
      <h3>No matching requests right now</h3>
      <p>New requests that fit your services and areas will appear here. You can add more services or areas in your profile.</p>
      <a class="btn btn-outline" href="/provider/profile.php">Edit profile</a>
    </div>
  <?php endif; ?>

  <?php foreach ($requests as $r): ?>
    <article class="ticket">
      <div class="ticket-main">
        <div class="ticket-top">
          <h3><?= e($r['category_name']) ?></h3>
          <?= $r['my_offer_id'] ? offerStatusBadge($r['my_offer_status']) : badge('New', 'info') ?>
        </div>
        <p style="margin:.25rem 0 0"><?= e(snippet($r['description'], 180)) ?></p>
        <div class="req-meta">
          <span><?= icon('clock') ?> Needed by <?= e(formatDateTime($r['urgency'])) ?></span>
          <span><?= icon('pin') ?> <?= e(snippet($r['location'], 50)) ?></span>
          <?php if ((int) $r['image_count'] > 0): ?><span><?= icon('camera') ?> <?= (int) $r['image_count'] ?> photo<?= (int) $r['image_count'] === 1 ? '' : 's' ?></span><?php endif; ?>
        </div>
      </div>
      <div class="ticket-stub">
        <div>
          <span class="stub-label">Customer budget</span>
          <strong class="stub-value"><?= e(money($r['min_budget'])) ?> to <?= e(money($r['max_budget'])) ?></strong>
        </div>
        <a class="btn btn-primary btn-sm" href="/provider/request.php?id=<?= (int) $r['id'] ?>"><?= $r['my_offer_id'] ? 'View request' : 'Send an offer' ?></a>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
