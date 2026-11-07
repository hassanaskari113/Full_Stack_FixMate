<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once BASE_PATH . '/app/requests.php';
require_once BASE_PATH . '/app/offers.php';
requireRole('C');

$user = currentUser();
$id = (int) ($_GET['id'] ?? 0);
$request = getCustomerRequest($id, (int) $user['id']) ?? notFound();

if (isPost()) {
    verifyCsrf();
    $action  = (string) ($_POST['action'] ?? '');
    $offerId = (int) ($_POST['offer_id'] ?? 0);

    if ($action === 'accept') {
        [$ok, $result] = acceptOffer($offerId, (int) $user['id']);
        if ($ok) {
            flash('success', 'Offer accepted! Your booking is confirmed.');
            redirect('/booking.php?id=' . $result);
        }
        flash('error', $result);
    } elseif ($action === 'reject') {
        $done = rejectOffer($offerId, (int) $user['id']);
        flash($done ? 'success' : 'error', $done ? 'Offer rejected.' : 'That offer could not be rejected.');
    }
    redirect('/customer/request.php?id=' . $id);
}

$images = getRequestImages($id);
$offers = getRequestOffers($id);
$isOpen = $request['status'] === 'O';
$pastDue = $isOpen && strtotime($request['urgency']) <= time();

$pageTitle = 'Request details';
require_once BASE_PATH . '/includes/header.php';
$customerNavActive = 'requests';
require BASE_PATH . '/includes/customer_nav.php';
?>
<div class="container section">
  <p><a class="back-link" href="/customer/requests.php">Back to my requests</a></p>

  <div class="card mb-1">
    <div class="card-head">
      <h1 style="margin:0;font-size:1.6rem"><?= e($request['category_name']) ?></h1>
      <?= requestStatusBadge($request['status']) ?>
    </div>
    <div class="kv">
      <div><small>Budget</small><?= e(money($request['min_budget'])) ?> – <?= e(money($request['max_budget'])) ?></div>
      <div><small>Needed by</small><?= e(formatDateTime($request['urgency'])) ?></div>
      <div><small>Posted</small><?= e(formatDateTime($request['created_at'])) ?></div>
    </div>
    <div class="label-sm">Description</div>
    <p class="pre-wrap"><?= e($request['description']) ?></p>
    <div class="label-sm">Location</div>
    <p class="pre-wrap"><?= e($request['location']) ?></p>
    <?php if ($images): ?><div class="label-sm">Photos</div><?= gallery($images) ?><?php endif; ?>
    <?php if ($pastDue): ?>
      <div class="alert alert-warning">This request is past its deadline, so providers no longer see it. You can still accept an offer whose arrival time is in the future.</div>
    <?php endif; ?>
  </div>

  <h2>Offers (<?= count($offers) ?>)</h2>
  <?php if (!$offers): ?>
    <div class="card empty-state"><h3>No offers yet</h3><p>Matching providers have been notified through their dashboard. Check back soon.</p></div>
  <?php endif; ?>

  <?php foreach ($offers as $o):
      $cls = $o['status'] === 'A' ? 'is-accepted' : ($o['status'] === 'P' ? 'is-pending' : '');
      $providerName = userDisplayName($o);
  ?>
    <div class="card offer-card <?= e($cls) ?> mb-1">
      <div class="card-head">
        <div class="person">
          <?= avatar($o) ?>
          <div class="person-info">
            <strong><?= e($providerName) ?></strong>
            <small><?= (int) $o['experience'] ?> yr<?= (int) $o['experience'] === 1 ? '' : 's' ?> experience</small>
            <small><?= ratingStars($o['avg_rating'], (int) $o['review_count']) ?></small>
          </div>
        </div>
        <div style="text-align:right"><div class="price"><?= e(money($o['price'])) ?></div><?= offerStatusBadge($o['status']) ?></div>
      </div>
      <div class="req-meta"><span><?= icon('truck') ?> Arrives <?= e(formatDateTime($o['arrival_time'])) ?></span><span>Sent <?= e(formatDateTime($o['created_at'])) ?></span></div>

      <?php if ($o['status'] === 'P' && $isOpen): ?>
        <div class="row-actions">
          <form method="post" data-confirm="Accept the offer from <?= e($providerName) ?> for <?= e(money($o['price'])) ?>? A booking will be created, the request will close, and all other pending offers will be rejected.">
            <?= csrfField() ?><input type="hidden" name="action" value="accept"><input type="hidden" name="offer_id" value="<?= (int) $o['id'] ?>">
            <button type="submit" class="btn btn-primary btn-sm">Accept offer</button>
          </form>
          <form method="post" data-confirm="Reject this offer?">
            <?= csrfField() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="offer_id" value="<?= (int) $o['id'] ?>">
            <button type="submit" class="btn btn-outline btn-sm">Reject</button>
          </form>
        </div>
      <?php elseif ($o['booking_id']): ?>
        <a class="btn btn-outline btn-sm" href="/booking.php?id=<?= (int) $o['booking_id'] ?>">View booking</a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
