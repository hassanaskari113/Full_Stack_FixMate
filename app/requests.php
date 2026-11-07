<?php
declare(strict_types=1);

/** "2026-11-06T15:30" (datetime-local input) -> "2026-11-06 15:30:00", or null if invalid. */
function parseDateTimeLocal(string $s): ?string
{
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $s);
    if (!$dt || DateTime::getLastErrors() || $dt->format('Y-m-d\TH:i') !== $s) {
        return null;
    }
    return $dt->format('Y-m-d H:i:00');
}

/** Positive amount with up to 2 decimals that fits DECIMAL(7,2); returns "123.50" or null. */
function parseMoney(string $s): ?string
{
    $s = trim($s);
    if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/', $s) || (float) $s <= 0) {
        return null;
    }
    return number_format((float) $s, 2, '.', '');
}

function getRequestImages(int $requestId): array
{
    $stmt = db()->prepare('SELECT image FROM request_images WHERE request_id = ? ORDER BY id');
    $stmt->execute([$requestId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** A request, only if it belongs to this customer. */
function getCustomerRequest(int $id, int $customerId): ?array
{
    $stmt = db()->prepare(
        'SELECT r.*, c.name AS category_name
         FROM service_requests r JOIN service_categories c ON c.id = r.category_id
         WHERE r.id = ? AND r.customer_id = ?'
    );
    $stmt->execute([$id, $customerId]);
    return $stmt->fetch() ?: null;
}

/** All offers on a request, with provider details and rating. */
function getRequestOffers(int $requestId): array
{
    $stmt = db()->prepare(
        "SELECT o.id, o.price, o.arrival_time, o.status, o.created_at, o.service_provider_id,
                pp.experience, u.first_name, u.last_name, u.username, u.profile_picture,
                b.id AS booking_id,
                (SELECT ROUND(AVG(rv.rating), 1) FROM reviews rv
                   JOIN bookings b2 ON b2.id = rv.booking_id
                   JOIN offers o2 ON o2.id = b2.offer_id
                  WHERE o2.service_provider_id = o.service_provider_id) AS avg_rating,
                (SELECT COUNT(*) FROM reviews rv
                   JOIN bookings b2 ON b2.id = rv.booking_id
                   JOIN offers o2 ON o2.id = b2.offer_id
                  WHERE o2.service_provider_id = o.service_provider_id) AS review_count
         FROM offers o
         JOIN provider_profiles pp ON pp.id = o.service_provider_id
         JOIN users u ON u.id = pp.user_id
         LEFT JOIN bookings b ON b.offer_id = o.id
         WHERE o.service_request_id = ?
         ORDER BY FIELD(o.status, 'A', 'P', 'R', 'E'), o.price ASC"
    );
    $stmt->execute([$requestId]);
    return $stmt->fetchAll();
}

/** Image files (request photos + review photos) belonging to these requests. */
function filesForRequestIds(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $ids = array_values($ids);

    $stmt = db()->prepare("SELECT image FROM request_images WHERE request_id IN ($in)");
    $stmt->execute($ids);
    $files = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = db()->prepare(
        "SELECT rv.image FROM review_images rv
         JOIN reviews r ON r.id = rv.review_id
         JOIN bookings b ON b.id = r.booking_id
         JOIN offers o ON o.id = b.offer_id
         WHERE o.service_request_id IN ($in)"
    );
    $stmt->execute($ids);
    return array_merge($files, $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function filesForCategory(int $categoryId): array
{
    $stmt = db()->prepare('SELECT id FROM service_requests WHERE category_id = ?');
    $stmt->execute([$categoryId]);
    return filesForRequestIds(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

function deleteFiles(array $files): void
{
    foreach ($files as $f) {
        deleteUploadedFile((string) $f);
    }
}

/**
 * Expire open requests (one request, or every request past its deadline) and
 * expire their pending offers, in one transaction. Returns how many requests changed.
 */
function expireRequests(?int $requestId = null): int
{
    $pdo    = db();
    $where  = "r.status = 'O'" . ($requestId !== null ? ' AND r.id = ?' : ' AND r.urgency <= NOW()');
    $params = $requestId !== null ? [$requestId] : [];

    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            "UPDATE offers o JOIN service_requests r ON r.id = o.service_request_id
             SET o.status = 'E' WHERE o.status = 'P' AND $where"
        )->execute($params);

        $stmt = $pdo->prepare("UPDATE service_requests r SET r.status = 'E' WHERE $where");
        $stmt->execute($params);
        $n = $stmt->rowCount();
        $pdo->commit();
        return $n;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $ex;
    }
}
