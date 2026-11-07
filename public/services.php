<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = currentUser();

$rows = db()->query(
    'SELECT c.id AS cat_id, c.name AS cat_name, s.name AS service_name
     FROM service_categories c LEFT JOIN services s ON s.category_id = c.id
     ORDER BY c.id, s.name'
)->fetchAll();

$grouped = [];
foreach ($rows as $r) {
    $id = (int) $r['cat_id'];
    $grouped[$id]['name'] = $r['cat_name'];
    $grouped[$id]['services'] = $grouped[$id]['services'] ?? [];
    if ($r['service_name'] !== null) {
        $grouped[$id]['services'][] = $r['service_name'];
    }
}

$pageTitle = 'Services';
require_once BASE_PATH . '/includes/header.php';
?>
<section class="section">
  <div class="container">
    <div class="section-title">
      <h1>Every service on FixMate</h1>
      <p>Choose the category that fits when you post a request. Providers see requests in the categories they offer.</p>
    </div>

    <?php if (!$grouped): ?>
      <div class="card empty-state"><h3>No services yet</h3><p>Categories and services appear here once an administrator adds them.</p></div>
    <?php else: ?>
      <div class="search-box">
        <?= icon('search') ?>
        <input type="text" id="svcSearch" placeholder="Search a service, like leak repair" autocomplete="off" aria-label="Search services">
      </div>

      <div class="dir">
        <?php foreach ($grouped as $catId => $cat): $n = count($cat['services']); ?>
          <section class="dir-row" id="cat-<?= (int) $catId ?>" data-cat="<?= e(mb_strtolower($cat['name'])) ?>">
            <h2 class="dir-name" style="margin:0"><?= e($cat['name']) ?></h2>
            <div class="service-chips">
              <?php if ($cat['services']): foreach ($cat['services'] as $svc): ?>
                <span class="chip"><?= e($svc) ?></span>
              <?php endforeach; else: ?>
                <span class="muted">No services listed yet.</span>
              <?php endif; ?>
            </div>
            <span class="dir-count"><?= $n ?> service<?= $n === 1 ? '' : 's' ?></span>
          </section>
        <?php endforeach; ?>
      </div>

      <div id="svcEmpty" class="card empty-state" hidden>
        <h3>Nothing matches that search</h3>
        <p>Try a shorter word, or browse the categories above.</p>
      </div>

      <p style="margin-top:2rem">
        <?php if (!$user): ?>
          <a class="btn btn-primary btn-lg" href="/signup.php">Sign up to post a request</a>
        <?php else: ?>
          <a class="btn btn-primary btn-lg" href="<?= e(dashboardPath($user)) ?>">Go to my dashboard</a>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
</section>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
