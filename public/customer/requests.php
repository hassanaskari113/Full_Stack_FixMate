<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('C');

$user = currentUser();
$tabs = ['all' => 'All', 'O' => 'Open', 'C' => 'Closed', 'E' => 'Expired'];
$status = isset($tabs[$_GET['status'] ?? '']) ? $_GET['status'] : 'all';

$sql = 'SELECT r.id, r.description, r.min_budget, r.max_budget, r.urgency, r.status, r.location, c.name AS category_name,
               (SELECT COUNT(*) FROM offers o WHERE o.service_request_id = r.id) AS offer_count,
               (SELECT COUNT(*) FROM offers o WHERE o.service_request_id = r.id AND o.status = \'P\') AS pending_count
        FROM service_requests r JOIN service_categories c ON c.id = r.category_id
        WHERE r.customer_id = ?';
$params = [$user['id']];
if ($status !== 'all') { $sql .= ' AND r.status = ?'; $params[] = $status; }
$sql .= ' ORDER BY r.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pageTitle = 'My requests';
require_once BASE_PATH . '/includes/header.php';
$customerNavActive = 'requests';
require BASE_PATH . '/includes/customer_nav.php';
?>
<div class="container section">
  <div class="page-head"><h1>My requests</h1><a class="btn btn-primary" href="/customer/request_new.php">+ New request</a></div>

  <div class="pill-tabs">
    <?php foreach ($tabs as $k => $label): ?>
      <a href="/customer/requests.php?status=<?= e($k) ?>"<?= $status === $k ? ' class="active"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$requests): ?>
    <div class="card empty-state"><h3>No requests here</h3><p>Requests you post will be listed here with their offers.</p>
      <a class="btn btn-primary" href="/customer/request_new.php">Post a request</a></div>
  <?php endif; ?>

  <?php foreach ($requests as $r): ?>
    <article class="ticket">
      <div class="ticket-main">
        <div class="ticket-top">
          <h3><?= e($r['category_name']) ?></h3>
          <?= requestStatusBadge($r['status']) ?>
        </div>
        <p style="margin:.25rem 0 0"><?= e(snippet($r['description'], 180)) ?></p>
        <div class="req-meta">
          <span><?= icon('clock') ?> Needed by <?= e(formatDateTime($r['urgency'])) ?></span>
          <span><?= icon('pin') ?> <?= e(snippet($r['location'], 50)) ?></span>
        </div>
      </div>
      <div class="ticket-stub">
        <div>
          <span class="stub-label">Budget</span>
          <strong class="stub-value"><?= e(money($r['min_budget'])) ?> to <?= e(money($r['max_budget'])) ?></strong>
          <span class="stub-note"><?= (int) $r['offer_count'] ?> offer<?= (int) $r['offer_count'] === 1 ? '' : 's' ?><?= (int) $r['pending_count'] > 0 ? ', ' . (int) $r['pending_count'] . ' waiting' : '' ?></span>
        </div>
        <a class="btn btn-primary btn-sm" href="/customer/request.php?id=<?= (int) $r['id'] ?>">View offers</a>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
