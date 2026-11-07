<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once BASE_PATH . '/app/reviews.php';
require_once BASE_PATH . '/app/requests.php';
requireAdmin();

if (isPost()) {
    verifyCsrf();
    if ((string) ($_POST['action'] ?? '') === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $files = array_merge(...array_values(reviewImagesMap([$id]) ?: [[]]));
        $stmt = db()->prepare('DELETE FROM reviews WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount()) { deleteFiles($files); flash('success', 'Review deleted.'); }
        else { flash('error', 'That review no longer exists.'); }
    }
    redirect('/admin/reviews.php');
}

$reviews = db()->query(
    'SELECT rv.id, rv.rating, rv.text, rv.created_at, b.id AS booking_id, c.name AS category_name,
            cu.first_name AS cu_first, cu.last_name AS cu_last, cu.username AS cu_username,
            pu.first_name AS pu_first, pu.last_name AS pu_last, pu.username AS pu_username
     FROM reviews rv
     JOIN bookings b ON b.id = rv.booking_id
     JOIN offers o ON o.id = b.offer_id
     JOIN service_requests r ON r.id = o.service_request_id
     JOIN service_categories c ON c.id = r.category_id
     JOIN users cu ON cu.id = r.customer_id
     JOIN provider_profiles pp ON pp.id = o.service_provider_id
     JOIN users pu ON pu.id = pp.user_id
     ORDER BY rv.id DESC LIMIT 200'
)->fetchAll();
$imageMap = reviewImagesMap(array_map('intval', array_column($reviews, 'id')));

$pageTitle   = 'Reviews';
$adminActive = 'reviews';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/admin_start.php';
?>
<div class="page-head"><h1>Reviews</h1></div>

<?php if (!$reviews): ?>
  <div class="card empty-state"><h3>No reviews yet</h3><p>Reviews appear here after customers rate completed bookings.</p></div>
<?php endif; ?>

<?php foreach ($reviews as $r): ?>
  <div class="card review-card mb-1">
    <div class="card-head">
      <div>
        <strong><?= e($r['category_name']) ?></strong> · booking #<?= (int) $r['booking_id'] ?><br>
        <small class="muted"><?= e(userDisplayName(['first_name' => $r['cu_first'], 'last_name' => $r['cu_last'], 'username' => $r['cu_username']])) ?>
          reviewed <?= e(userDisplayName(['first_name' => $r['pu_first'], 'last_name' => $r['pu_last'], 'username' => $r['pu_username']])) ?>
          · <?= e(formatDateTime($r['created_at'])) ?></small>
      </div>
      <?= starString((int) $r['rating']) ?>
    </div>
    <p class="pre-wrap"><?= e($r['text']) ?></p>
    <?= gallery($imageMap[(int) $r['id']] ?? []) ?>
    <form method="post" data-confirm="Delete this review and its images? The customer will be able to review this booking again.">
      <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <button type="submit" class="btn btn-danger btn-sm">Delete review</button>
    </form>
  </div>
<?php endforeach; ?>
<?php
require_once BASE_PATH . '/includes/admin_end.php';
require_once BASE_PATH . '/includes/footer.php';
