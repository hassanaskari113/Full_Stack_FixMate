<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once BASE_PATH . '/app/bookings.php';
require_once BASE_PATH . '/app/requests.php';
requireLogin();
if (isAdmin()) { forbidden('Administrators manage bookings from the admin panel.'); }

$user = currentUser();
$id = (int) ($_GET['id'] ?? 0);
$booking = getBookingForUser($id, $user) ?? notFound();

if (isPost()) {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'complete') {
        [$ok, $msg] = completeBooking($id, (int) $user['id']);
    } elseif ($action === 'cancel') {
        [$ok, $msg] = cancelBooking($id, (int) $user['id'], (string) ($_POST['reason'] ?? ''));
    } else {
        [$ok, $msg] = [false, 'Unknown action.'];
    }
    flash($ok ? 'success' : 'error', $msg);
    redirect('/booking.php?id=' . $id);
}

$isCustomer = (int) $user['id'] === (int) $booking['customer_id'];
$other      = $isCustomer ? $booking['provider'] : $booking['customer'];
$images     = getRequestImages((int) $booking['service_request_id']);
$confirmed  = $booking['status'] === 'CONF';

$reviewImages = [];
if ($booking['review_id']) {
    $stmt = db()->prepare('SELECT image FROM review_images WHERE review_id = ? ORDER BY id');
    $stmt->execute([$booking['review_id']]);
    $reviewImages = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$pageTitle = 'Booking #' . $id;
require_once BASE_PATH . '/includes/header.php';
$roleNavActive = 'bookings';
require BASE_PATH . '/includes/role_nav.php';
?>
<div class="container section">
  <p><a class="back-link" href="/bookings.php">Back to bookings</a></p>
  <div class="split">
    <div>
      <div class="card mb-1">
        <div class="card-head">
          <h1 style="margin:0;font-size:1.6rem"><?= e($booking['category_name']) ?> <span class="muted" style="font-weight:400">#<?= (int) $booking['id'] ?></span></h1>
          <?= bookingStatusBadge($booking['status']) ?>
        </div>
        <div class="kv">
          <div><small>Scheduled</small><?= e(formatDateTime($booking['scheduled_time'])) ?></div>
          <div><small>Final price</small><span class="price"><?= e(money($booking['final_price'])) ?></span></div>
          <div><small>Booked on</small><?= e(formatDateTime($booking['created_at'])) ?></div>
        </div>
        <div class="label-sm">Job description</div>
        <p class="pre-wrap"><?= e($booking['description']) ?></p>
        <div class="label-sm">Location</div>
        <p class="pre-wrap"><?= e($booking['location']) ?></p>
        <?php if ($images): ?><div class="label-sm">Photos</div><?= gallery($images) ?><?php endif; ?>
        <?php if ($booking['status'] === 'CANC'): ?>
          <div class="alert alert-error"><strong>Cancelled.</strong> Reason: <?= e($booking['cancellation_reason']) ?></div>
        <?php endif; ?>
      </div>

      <?php if ($booking['status'] === 'COMP'): ?>
        <div class="card">
          <h3>Review</h3>
          <?php if ($booking['review_id']): ?>
            <p><span class="stars"><?= str_repeat('★', (int) $booking['rating']) . str_repeat('☆', 5 - (int) $booking['rating']) ?></span>
               <small class="muted"><?= e(formatDateTime($booking['review_created_at'])) ?></small></p>
            <p class="pre-wrap"><?= e($booking['review_text']) ?></p>
            <?= gallery($reviewImages) ?>
          <?php elseif ($isCustomer): ?>
            <p class="muted">The job is done. Tell others how it went.</p>
            <a class="btn btn-primary" href="/customer/review.php?booking=<?= (int) $booking['id'] ?>">Leave a review</a>
          <?php else: ?>
            <p class="muted">The customer has not left a review yet.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div>
      <div class="card mb-1">
        <h3><?= $isCustomer ? 'Your provider' : 'Your customer' ?></h3>
        <div class="person" style="margin-bottom:.75rem">
          <?= avatar($other) ?>
          <div class="person-info"><strong><?= e(userDisplayName($other)) ?></strong>
            <?php if ($isCustomer): ?><small><?= (int) $booking['experience'] ?> yrs experience</small><?php endif; ?></div>
        </div>
        <p style="margin:0"><?= icon('mail') ?> <?= e($other['email']) ?></p>
        <p style="margin:0"><?= icon('phone') ?> <?= $other['phone_number'] ? e($other['phone_number']) : '<span class="muted">No phone number</span>' ?></p>
      </div>

      <?php if ($confirmed): ?>
        <div class="card">
          <h3>Manage booking</h3>
          <form method="post" data-confirm="Mark this booking as completed?">
            <?= csrfField() ?><input type="hidden" name="action" value="complete">
            <button type="submit" class="btn btn-primary btn-block">Mark as completed</button>
          </form>
          <details class="cancel-box">
            <summary>Cancel this booking</summary>
            <form method="post" data-confirm="Cancel this booking? This cannot be undone.">
              <?= csrfField() ?><input type="hidden" name="action" value="cancel">
              <div class="form-group">
                <label for="reason">Reason for cancelling</label>
                <textarea id="reason" name="reason" maxlength="500" style="min-height:80px" required></textarea>
              </div>
              <button type="submit" class="btn btn-danger btn-block">Cancel booking</button>
            </form>
          </details>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
