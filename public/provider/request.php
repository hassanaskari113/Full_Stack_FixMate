<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once BASE_PATH . '/app/providers.php';
require_once BASE_PATH . '/app/requests.php';
require_once BASE_PATH . '/app/offers.php';
$profile = requireApprovedProvider();

$id = (int) ($_GET['id'] ?? 0);
$request = getRequestForProvider((int) $profile['id'], $id) ?? notFound();

$errors = [];
$price = '';
$arrival = '';

if (isPost()) {
    verifyCsrf();
    $price   = trim((string) ($_POST['price'] ?? ''));
    $arrival = trim((string) ($_POST['arrival_time'] ?? ''));

    $p = parseMoney($price);
    if ($p === null) { $errors['price'] = 'Enter a price above 0 (up to 99999.99).'; }
    $a = parseDateTimeLocal($arrival);
    if ($a === null) { $errors['arrival_time'] = 'Choose a valid date and time.'; }
    elseif (strtotime($a) <= time()) { $errors['arrival_time'] = 'The arrival time must be in the future.'; }

    if (!$errors) {
        [$ok, $msg] = submitOffer($profile, $id, $p, $a);
        flash($ok ? 'success' : 'error', $msg);
        redirect($ok ? '/provider/offers.php' : '/provider/request.php?id=' . $id);
    }
}

$images  = getRequestImages($id);
$myOffer = null;
if ($request['my_offer_id']) {
    $stmt = db()->prepare('SELECT o.price, o.arrival_time, o.status, b.id AS booking_id FROM offers o LEFT JOIN bookings b ON b.offer_id = o.id WHERE o.id = ?');
    $stmt->execute([$request['my_offer_id']]);
    $myOffer = $stmt->fetch();
}
$canOffer = !$myOffer && $request['status'] === 'O' && strtotime($request['urgency']) > time();

$pageTitle = 'Request details';
require_once BASE_PATH . '/includes/header.php';
$providerNavActive = 'requests';
require BASE_PATH . '/includes/provider_nav.php';
?>
<div class="container section">
  <p><a class="back-link" href="/provider/requests.php">Back to matching requests</a></p>
  <div class="split">
    <div class="card">
      <div class="card-head">
        <h1 style="margin:0;font-size:1.6rem"><?= e($request['category_name']) ?></h1>
        <?= requestStatusBadge($request['status']) ?>
      </div>
      <div class="kv">
        <div><small>Customer</small><?= e($request['customer_first_name']) ?></div>
        <div><small>Budget</small><?= e(money($request['min_budget'])) ?> – <?= e(money($request['max_budget'])) ?></div>
        <div><small>Needed by</small><?= e(formatDateTime($request['urgency'])) ?></div>
      </div>
      <div class="label-sm">Description</div>
      <p class="pre-wrap"><?= e($request['description']) ?></p>
      <div class="label-sm">Location</div>
      <p class="pre-wrap"><?= e($request['location']) ?></p>
      <?php if ($images): ?><div class="label-sm">Photos</div><?= gallery($images) ?><?php endif; ?>
    </div>

    <div class="card">
      <?php if ($myOffer): ?>
        <h3>Your offer</h3>
        <div class="price"><?= e(money($myOffer['price'])) ?></div>
        <p class="muted">Arrival: <?= e(formatDateTime($myOffer['arrival_time'])) ?></p>
        <p><?= offerStatusBadge($myOffer['status']) ?></p>
        <?php if ($myOffer['booking_id']): ?><a class="btn btn-primary btn-block" href="/booking.php?id=<?= (int) $myOffer['booking_id'] ?>">View booking</a><?php endif; ?>
      <?php elseif ($canOffer): ?>
        <h3>Send an offer</h3>
        <form method="post" novalidate>
          <?= csrfField() ?>
          <div class="form-group">
            <label for="price">Your price (Rs)</label>
            <input type="number" id="price" name="price" min="1" max="99999.99" step="0.01" value="<?= e($price) ?>" class="<?= isset($errors['price']) ? 'input-error' : '' ?>" required>
            <?= fieldError($errors, 'price') ?>
          </div>
          <div class="form-group">
            <label for="arrival_time">Arrival time</label>
            <input type="datetime-local" id="arrival_time" name="arrival_time" min="<?= e(date('Y-m-d\TH:i')) ?>" value="<?= e($arrival) ?>" class="<?= isset($errors['arrival_time']) ? 'input-error' : '' ?>" required>
            <?= fieldError($errors, 'arrival_time') ?>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Send offer</button>
          <p class="form-hint">You can send only one offer per request.</p>
        </form>
      <?php else: ?>
        <h3>Offers closed</h3>
        <p class="muted">This request is no longer accepting offers.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
