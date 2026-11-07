<?php
declare(strict_types=1);

/**
 * Open, not-yet-due requests that fit a provider:
 *   - the request category equals the category of one of the provider's services, AND
 *   - the request's free-text location contains the name of one of the provider's areas.
 * Pass $onlyId to fetch a single request through the same rules.
 */
function matchingRequests(int $profileId, ?int $onlyId = null): array
{
    $sql = "SELECT r.id, r.description, r.min_budget, r.max_budget, r.urgency, r.status, r.created_at,
                   r.location, r.category_id, c.name AS category_name, u.first_name AS customer_first_name,
                   o.id AS my_offer_id, o.status AS my_offer_status,
                   (SELECT COUNT(*) FROM request_images ri WHERE ri.request_id = r.id) AS image_count
            FROM service_requests r
            JOIN service_categories c ON c.id = r.category_id
            JOIN users u ON u.id = r.customer_id
            LEFT JOIN offers o ON o.service_request_id = r.id AND o.service_provider_id = :p1
            WHERE r.status = 'O' AND r.urgency > NOW()
              AND r.category_id IN (
                    SELECT s.category_id FROM provider_profile_services pps
                    JOIN services s ON s.id = pps.service_id
                    WHERE pps.provider_profile_id = :p2)
              AND EXISTS (
                    SELECT 1 FROM provider_profile_service_areas ppa
                    JOIN service_areas a ON a.id = ppa.service_area_id
                    WHERE ppa.provider_profile_id = :p3 AND LOCATE(a.name, r.location) > 0)";
    $params = [':p1' => $profileId, ':p2' => $profileId, ':p3' => $profileId];
    if ($onlyId !== null) {
        $sql .= ' AND r.id = :rid';
        $params[':rid'] = $onlyId;
    }
    $sql .= ' ORDER BY r.urgency ASC, r.id DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * A request this provider may view: either it currently matches, or the provider
 * already sent an offer on it (so they can still see it after it closes).
 */
function getRequestForProvider(int $profileId, int $requestId): ?array
{
    if ($rows = matchingRequests($profileId, $requestId)) {
        return $rows[0];
    }
    $stmt = db()->prepare(
        'SELECT r.id, r.description, r.min_budget, r.max_budget, r.urgency, r.status, r.created_at,
                r.location, r.category_id, c.name AS category_name, u.first_name AS customer_first_name,
                o.id AS my_offer_id, o.status AS my_offer_status, 0 AS image_count
         FROM service_requests r
         JOIN service_categories c ON c.id = r.category_id
         JOIN users u ON u.id = r.customer_id
         JOIN offers o ON o.service_request_id = r.id AND o.service_provider_id = ?
         WHERE r.id = ?'
    );
    $stmt->execute([$profileId, $requestId]);
    return $stmt->fetch() ?: null;
}
