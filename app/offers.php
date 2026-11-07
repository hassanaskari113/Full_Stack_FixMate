<?php
declare(strict_types=1);

require_once __DIR__ . '/matching.php';

/** Provider submits an offer. Returns [ok, message]. */
function submitOffer(array $profile, int $requestId, string $price, string $arrival): array
{
    if (!isApprovedProvider($profile)) {
        return [false, 'Only approved providers can submit offers.'];
    }
    if (!matchingRequests((int) $profile['id'], $requestId)) {
        return [false, 'This request is not available to you.'];
    }
    try {
        db()->prepare('INSERT INTO offers (service_request_id, service_provider_id, price, arrival_time) VALUES (?, ?, ?, ?)')
            ->execute([$requestId, $profile['id'], $price, $arrival]);
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') {
            return [false, 'You have already submitted an offer for this request.'];
        }
        error_log('Submit offer failed: ' . $ex->getMessage());
        return [false, 'Could not submit your offer. Please try again.'];
    }
    return [true, 'Your offer has been sent to the customer.'];
}

/**
 * Customer accepts an offer. ONE transaction:
 * accept it, reject the other pending offers, create the booking, close the request.
 * Returns [true, bookingId] or [false, message].
 */
function acceptOffer(int $offerId, int $customerId): array
{
    $pdo = db();
    try {
        $pdo->beginTransaction();

        // Locks the offer and request rows so two simultaneous accepts cannot both succeed.
        $stmt = $pdo->prepare(
            'SELECT o.id, o.service_request_id, o.price, o.arrival_time, o.status AS offer_status,
                    r.customer_id, r.status AS request_status, pp.verification_status
             FROM offers o
             JOIN service_requests r   ON r.id  = o.service_request_id
             JOIN provider_profiles pp ON pp.id = o.service_provider_id
             WHERE o.id = ? FOR UPDATE'
        );
        $stmt->execute([$offerId]);
        $o = $stmt->fetch();

        $fail = null;
        if (!$o || (int) $o['customer_id'] !== $customerId)  { $fail = 'Offer not found.'; }
        elseif ($o['request_status'] !== 'O')                { $fail = 'This request is no longer open.'; }
        elseif ($o['offer_status'] !== 'P')                  { $fail = 'This offer can no longer be accepted.'; }
        elseif ($o['verification_status'] !== 'A')           { $fail = 'This provider is no longer approved.'; }
        elseif (strtotime($o['arrival_time']) <= time())     { $fail = 'This offer\'s arrival time has already passed.'; }

        if ($fail) {
            $pdo->rollBack();
            return [false, $fail];
        }

        $pdo->prepare("UPDATE offers SET status = 'A' WHERE id = ?")->execute([$offerId]);
        $pdo->prepare("UPDATE offers SET status = 'R' WHERE service_request_id = ? AND id <> ? AND status = 'P'")
            ->execute([$o['service_request_id'], $offerId]);
        $pdo->prepare('INSERT INTO bookings (offer_id, scheduled_time, final_price) VALUES (?, ?, ?)')
            ->execute([$offerId, $o['arrival_time'], $o['price']]);
        $bookingId = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE service_requests SET status = 'C' WHERE id = ?")->execute([$o['service_request_id']]);

        $pdo->commit();
        return [true, $bookingId];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('Accept offer failed: ' . $ex->getMessage());
        return [false, 'Something went wrong. Nothing was changed. Please try again.'];
    }
}

/** Customer rejects one pending offer on their own open request. */
function rejectOffer(int $offerId, int $customerId): bool
{
    $stmt = db()->prepare(
        "UPDATE offers o JOIN service_requests r ON r.id = o.service_request_id
         SET o.status = 'R'
         WHERE o.id = ? AND o.status = 'P' AND r.customer_id = ? AND r.status = 'O'"
    );
    $stmt->execute([$offerId, $customerId]);
    return $stmt->rowCount() > 0;
}
