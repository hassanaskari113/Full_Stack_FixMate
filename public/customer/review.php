<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
requireRole('C');

$user = currentUser();
$bookingId = (int) ($_GET['booking'] ?? 0);

// The booking must belong to THIS customer
$stmt = db()->prepare(
    'SELECT b.id, b.status, b.scheduled_time, b.final_price, c.name AS category_name, rv.id AS review_id
     FROM bookings b
     JOIN offers o ON o.id = b.offer_id
     JOIN service_requests r ON r.id = o.service_request_id
     JOIN service_categories c ON c.id = r.category_id
     LEFT JOIN reviews rv ON rv.booking_id = b.id
     WHERE b.id = ? AND r.customer_id = ?'
);
$stmt->execute([$bookingId, $user['id']]);
$booking = $stmt->fetch() ?: notFound();

if ($booking['status'] !== 'COMP') {
    flash('error', 'You can only review a booking after it has been completed.');
    redirect('/booking.php?id=' . $bookingId);
}
if ($booking['review_id']) {
    flash('info', 'You have already reviewed this booking.');
    redirect('/booking.php?id=' . $bookingId);
}

$errors = [];
$rating = '';
$text   = '';

if (isPost()) {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors['general'] = 'The submitted data was too large. Please upload fewer or smaller images.';
    } else {
        verifyCsrf();
        $rating = (string) ($_POST['rating'] ?? '');
        $text   = trim((string) ($_POST['text'] ?? ''));

        if (!preg_match('/^[1-5]$/', $rating)) { $errors['rating'] = 'Choose a rating from 1 to 5 stars.'; }
        $len = mb_strlen($text);
        if ($len < 3 || $len > 1000) { $errors['text'] = 'Write 3 to 1000 characters about your experience.'; }

        $images = normalizeMultiFiles($_FILES['images'] ?? null);
        if ($msg = validateImageList($images, 5)) { $errors['images'] = $msg; }

        if (!$errors) {
            $pdo = db();
            $stored = [];
            try {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO reviews (booking_id, text, rating) VALUES (?, ?, ?)')
                    ->execute([$bookingId, $text, (int) $rating]);
                $reviewId = (int) $pdo->lastInsertId();

                $ins = $pdo->prepare('INSERT INTO review_images (image, review_id) VALUES (?, ?)');
                foreach ($images as $img) {
                    $path = storeImage($img, 'review_images');
                    $stored[] = $path;
                    $ins->execute([$path, $reviewId]);
                }
                $pdo->commit();

                flash('success', 'Thank you! Your review has been posted.');
                redirect('/booking.php?id=' . $bookingId);
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                deleteFiles($stored);
                if ($ex instanceof PDOException && $ex->getCode() === '23000') {
                    flash('info', 'You have already reviewed this booking.');
                    redirect('/booking.php?id=' . $bookingId);
                }
                error_log('Create review failed: ' . $ex->getMessage());
                $errors['general'] = 'Something went wrong while saving your review. Please try again.';
            }
        }
    }
}

require_once BASE_PATH . '/app/requests.php';   // deleteFiles()
$pageTitle = 'Leave a review';
require_once BASE_PATH . '/includes/header.php';
$customerNavActive = 'bookings';
require BASE_PATH . '/includes/customer_nav.php';
?>
<div class="container section" style="max-width:680px">
  <p><a class="back-link" href="/booking.php?id=<?= (int) $bookingId ?>">Back to booking</a></p>
  <h1>Leave a review</h1>
  <p class="muted"><?= e($booking['category_name']) ?> · <?= e(formatDateTime($booking['scheduled_time'])) ?> · <?= e(money($booking['final_price'])) ?></p>

  <?php if (isset($errors['general'])): ?><div class="alert alert-error"><?= e($errors['general']) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="card" novalidate>
    <?= csrfField() ?>
    <div class="form-group">
      <label>Your rating</label>
      <div class="star-input">
        <?php for ($i = 5; $i >= 1; $i--): ?>
          <input type="radio" name="rating" id="star<?= $i ?>" value="<?= $i ?>" <?= $rating === (string) $i ? 'checked' : '' ?>>
          <label for="star<?= $i ?>" title="<?= $i ?> star<?= $i === 1 ? '' : 's' ?>">★</label>
        <?php endfor; ?>
      </div>
      <?= fieldError($errors, 'rating') ?>
    </div>

    <div class="form-group">
      <label for="text">Your review</label>
      <textarea id="text" name="text" maxlength="1000" class="<?= isset($errors['text']) ? 'input-error' : '' ?>" required placeholder="How was the service? Was the provider on time and professional?"><?= e($text) ?></textarea>
      <?= fieldError($errors, 'text') ?>
    </div>

    <div class="form-group">
      <label for="images">Photos <span class="muted">(optional)</span></label>
      <input type="file" id="images" name="images[]" multiple accept="image/jpeg,image/png,image/webp" class="<?= isset($errors['images']) ? 'input-error' : '' ?>">
      <p class="form-hint">Up to 5 images (JPG, PNG or WebP), 2 MB each. Choose them again if the form shows an error.</p>
      <?= fieldError($errors, 'images') ?>
    </div>

    <button type="submit" class="btn btn-primary btn-lg">Post review</button>
    <a class="btn btn-outline btn-lg" href="/booking.php?id=<?= (int) $bookingId ?>">Cancel</a>
  </form>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
