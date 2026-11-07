<?php
declare(strict_types=1);

/** A booking, only if $user is its customer or its provider. Otherwise null. */
function getBookingForUser(int $id, array $user): ?array
{
    $stmt = db()->prepare(
        'SELECT b.id, b.offer_id, b.scheduled_time, b.final_price, b.status, b.created_at, b.cancellation_reason,
                o.price AS offer_price, o.service_request_id,
                r.description, r.location, r.urgency, r.min_budget, r.max_budget, r.customer_id,
                r.status AS request_status, c.name AS category_name,
                pp.user_id AS provider_user_id, pp.experience,
                cu.first_name AS cu_first, cu.last_name AS cu_last, cu.username AS cu_username,
                cu.email AS cu_email, cu.phone_number AS cu_phone, cu.profile_picture AS cu_pic,
                pu.first_name AS pu_first, pu.last_name AS pu_last, pu.username AS pu_username,
                pu.email AS pu_email, pu.phone_number AS pu_phone, pu.profile_picture AS pu_pic,
                rv.id AS review_id, rv.rating, rv.text AS review_text, rv.created_at AS review_created_at
         FROM bookings b
         JOIN offers o ON o.id = b.offer_id
         JOIN service_requests r ON r.id = o.service_request_id
         JOIN service_categories c ON c.id = r.category_id
         JOIN provider_profiles pp ON pp.id = o.service_provider_id
         JOIN users cu ON cu.id = r.customer_id
         JOIN users pu ON pu.id = pp.user_id
         LEFT JOIN reviews rv ON rv.booking_id = b.id
         WHERE b.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $uid = (int) $user['id'];
    if ($uid !== (int) $row['customer_id'] && $uid !== (int) $row['provider_user_id']) {
        return null;                                   // not a participant: treated as "not found"
    }
    $row['customer'] = ['first_name' => $row['cu_first'], 'last_name' => $row['cu_last'], 'username' => $row['cu_username'],
                        'email' => $row['cu_email'], 'phone_number' => $row['cu_phone'], 'profile_picture' => $row['cu_pic']];
    $row['provider'] = ['first_name' => $row['pu_first'], 'last_name' => $row['pu_last'], 'username' => $row['pu_username'],
                        'email' => $row['pu_email'], 'phone_number' => $row['pu_phone'], 'profile_picture' => $row['pu_pic']];
    return $row;
}

/** Lock a booking row and load what the actions need. Call inside a transaction. */
function lockBooking(int $bookingId): ?array
{
    $stmt = db()->prepare(
        'SELECT b.id, b.status, o.id AS offer_id, o.service_request_id, r.customer_id, r.urgency,
                pp.user_id AS provider_user_id
         FROM bookings b
         JOIN offers o ON o.id = b.offer_id
         JOIN service_requests r ON r.id = o.service_request_id
         JOIN provider_profiles pp ON pp.id = o.service_provider_id
         WHERE b.id = ? FOR UPDATE'
    );
    $stmt->execute([$bookingId]);
    return $stmt->fetch() ?: null;
}

function isParticipant(array $b, int $userId): bool
{
    return $userId === (int) $b['customer_id'] || $userId === (int) $b['provider_user_id'];
}

/** Returns [ok, message]. */
function completeBooking(int $bookingId, int $userId): array
{
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $b = lockBooking($bookingId);
        if (!$b || !isParticipant($b, $userId)) { $pdo->rollBack(); return [false, 'Booking not found.']; }
        if ($b['status'] !== 'CONF')            { $pdo->rollBack(); return [false, 'Only confirmed bookings can be completed.']; }

        $pdo->prepare("UPDATE bookings SET status = 'COMP' WHERE id = ?")->execute([$bookingId]);
        $pdo->commit();
        return [true, 'Booking marked as completed.'];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('Complete booking failed: ' . $ex->getMessage());
        return [false, 'Something went wrong. Please try again.'];
    }
}

/**
 * Cancel a confirmed booking (reason required). If the request deadline is still in the
 * future the request reopens: the cancelled offer becomes Rejected and the other Rejected
 * offers go back to Pending. Otherwise the request stays Closed.
 */
function cancelBooking(int $bookingId, int $userId, string $reason): array
{
    $reason = trim($reason);
    $len = mb_strlen($reason);
    if ($len < 3 || $len > 500) {
        return [false, 'Please give a reason for cancelling (3 to 500 characters).'];
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $b = lockBooking($bookingId);
        if (!$b || !isParticipant($b, $userId)) { $pdo->rollBack(); return [false, 'Booking not found.']; }
        if ($b['status'] !== 'CONF')            { $pdo->rollBack(); return [false, 'Only confirmed bookings can be cancelled.']; }

        $pdo->prepare("UPDATE bookings SET status = 'CANC', cancellation_reason = ? WHERE id = ?")
            ->execute([$reason, $bookingId]);

        $reopened = strtotime($b['urgency']) > time();
        if ($reopened) {
            $pdo->prepare("UPDATE service_requests SET status = 'O' WHERE id = ? AND status = 'C'")->execute([$b['service_request_id']]);
            $pdo->prepare("UPDATE offers SET status = 'R' WHERE id = ?")->execute([$b['offer_id']]);
            $pdo->prepare("UPDATE offers SET status = 'P' WHERE service_request_id = ? AND id <> ? AND status = 'R'")
                ->execute([$b['service_request_id'], $b['offer_id']]);
        }
        $pdo->commit();

        return [true, $reopened
            ? 'Booking cancelled. The request is open again for offers.'
            : 'Booking cancelled. The request deadline has passed, so it stays closed.'];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('Cancel booking failed: ' . $ex->getMessage());
        return [false, 'Something went wrong. Please try again.'];
    }
}
