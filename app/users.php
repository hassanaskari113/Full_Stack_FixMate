<?php
declare(strict_types=1);

/**
 * Delete a user. The database cascades to their requests, offers, bookings and
 * reviews; this function also removes the image files those rows pointed to.
 */
function deleteUserWithFiles(int $userId): bool
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT profile_picture FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }

    $files = [$row['profile_picture']];

    // Images on requests this user posted
    $stmt = $pdo->prepare(
        'SELECT ri.image FROM request_images ri
         JOIN service_requests sr ON sr.id = ri.request_id
         WHERE sr.customer_id = ?'
    );
    $stmt->execute([$userId]);
    $files = array_merge($files, $stmt->fetchAll(PDO::FETCH_COLUMN));

    // Review images for bookings where this user was the customer OR the provider
    $stmt = $pdo->prepare(
        'SELECT rv.image FROM review_images rv
         JOIN reviews r            ON r.id  = rv.review_id
         JOIN bookings b           ON b.id  = r.booking_id
         JOIN offers o             ON o.id  = b.offer_id
         JOIN service_requests sr  ON sr.id = o.service_request_id
         LEFT JOIN provider_profiles pp ON pp.id = o.service_provider_id
         WHERE sr.customer_id = ? OR pp.user_id = ?'
    );
    $stmt->execute([$userId, $userId]);
    $files = array_merge($files, $stmt->fetchAll(PDO::FETCH_COLUMN));

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    foreach ($files as $f) {
        deleteUploadedFile((string) $f);     // only after the database delete succeeded
    }
    return true;
}
