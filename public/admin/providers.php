<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
requireAdmin();

$filters = ['P' => 'Pending', 'A' => 'Approved', 'R' => 'Rejected', 'all' => 'All'];

if (isPost()) {
    verifyCsrf();
    $id     = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $back   = isset($filters[$_POST['filter'] ?? '']) ? $_POST['filter'] : 'P';

    $newStatus = ['approve' => 'A', 'reject' => 'R'][$action] ?? null;

    if ($newStatus === null) {
        flash('error', 'Unknown action.');
    } else {
        $stmt = db()->prepare('UPDATE provider_profiles SET verification_status = ? WHERE id = ?');
        $stmt->execute([$newStatus, $id]);

        $exists = db()->prepare('SELECT 1 FROM provider_profiles WHERE id = ?');
        $exists->execute([$id]);
        if ($exists->fetch()) {
            flash('success', $newStatus === 'A' ? 'Provider approved.' : 'Provider rejected.');
        } else {
            flash('error', 'That provider profile no longer exists.');
        }
    }
    redirect('/admin/providers.php?status=' . $back);
}

$status = isset($filters[$_GET['status'] ?? '']) ? $_GET['status'] : 'P';

$counts = db()->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(verification_status = 'P'), 0) AS p,
            COALESCE(SUM(verification_status = 'A'), 0) AS a,
            COALESCE(SUM(verification_status = 'R'), 0) AS r
     FROM provider_profiles"
)->fetch();
$countFor = ['P' => $counts['p'], 'A' => $counts['a'], 'R' => $counts['r'], 'all' => $counts['total']];

$sql = "SELECT pp.id, pp.bio, pp.experience, pp.verification_status, pp.created_at,
               u.first_name, u.last_name, u.username, u.email, u.phone_number, u.profile_picture,
               (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR '||')
                  FROM provider_profile_services x JOIN services s ON s.id = x.service_id
                 WHERE x.provider_profile_id = pp.id) AS service_names,
               (SELECT GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR '||')
                  FROM provider_profile_service_areas y JOIN service_areas a ON a.id = y.service_area_id
                 WHERE y.provider_profile_id = pp.id) AS area_names
        FROM provider_profiles pp
        JOIN users u ON u.id = pp.user_id";
$params = [];
if ($status !== 'all') {
    $sql .= ' WHERE pp.verification_status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY pp.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$providers = $stmt->fetchAll();

$pageTitle   = 'Provider verification';
$adminActive = 'providers';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/admin_start.php';
?>
<div class="page-head"><h1>Provider verification</h1></div>

<div class="pill-tabs">
  <?php foreach ($filters as $key => $label): ?>
    <a href="/admin/providers.php?status=<?= e($key) ?>"<?= $status === $key ? ' class="active"' : '' ?>>
      <?= e($label) ?> (<?= (int) $countFor[$key] ?>)
    </a>
  <?php endforeach; ?>
</div>

<?php if (!$providers): ?>
  <div class="card empty-state">
    <h3>No <?= $status === 'all' ? '' : e(strtolower($filters[$status])) . ' ' ?>provider profiles</h3>
    <p>Profiles appear here after providers set them up.</p>
  </div>
<?php endif; ?>

<?php foreach ($providers as $p):
    $serviceNames = $p['service_names'] ? explode('||', $p['service_names']) : [];
    $areaNames    = $p['area_names'] ? explode('||', $p['area_names']) : [];
?>
  <div class="card mb-1">
    <div class="card-head">
      <div class="person">
        <?= avatar($p) ?>
        <div class="person-info">
          <strong><?= e(userDisplayName($p)) ?></strong>
          <small><?= e($p['email']) ?><?= $p['phone_number'] ? ' · ' . e($p['phone_number']) : '' ?></small>
        </div>
      </div>
      <?= verificationBadge($p['verification_status']) ?>
    </div>

    <div class="kv">
      <div><small>Experience</small><?= (int) $p['experience'] ?> year<?= (int) $p['experience'] === 1 ? '' : 's' ?></div>
      <div><small>Applied</small><?= e(formatDateTime($p['created_at'])) ?></div>
    </div>

    <?php if ($p['bio'] !== ''): ?>
      <div class="label-sm">Bio</div>
      <p class="pre-wrap"><?= e($p['bio']) ?></p>
    <?php endif; ?>

    <div class="label-sm">Services</div>
    <div class="service-chips"><?php foreach ($serviceNames as $n): ?><span class="chip"><?= e($n) ?></span><?php endforeach; ?></div>
    <div class="label-sm">Areas</div>
    <div class="service-chips"><?php foreach ($areaNames as $n): ?><span class="chip"><?= e($n) ?></span><?php endforeach; ?></div>

    <div class="row-actions" style="margin-top:1.25rem">
      <?php if ($p['verification_status'] !== 'A'): ?>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="approve">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <input type="hidden" name="filter" value="<?= e($status) ?>">
          <button type="submit" class="btn btn-primary btn-sm">Approve</button>
        </form>
      <?php endif; ?>
      <?php if ($p['verification_status'] !== 'R'): ?>
        <form method="post" data-confirm="Reject this provider? They will not be able to send offers until they resubmit and are approved.">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reject">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <input type="hidden" name="filter" value="<?= e($status) ?>">
          <button type="submit" class="btn btn-danger btn-sm">Reject</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php
require_once BASE_PATH . '/includes/admin_end.php';
require_once BASE_PATH . '/includes/footer.php';
