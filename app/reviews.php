<?php
declare(strict_types=1);

/** [reviewId => [image paths]] for a list of review IDs, in one query. */
function reviewImagesMap(array $reviewIds): array
{
    if (!$reviewIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($reviewIds), '?'));
    $stmt = db()->prepare("SELECT review_id, image FROM review_images WHERE review_id IN ($in) ORDER BY id");
    $stmt->execute(array_values($reviewIds));
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[(int) $r['review_id']][] = $r['image'];
    }
    return $map;
}

function starString(int $n): string
{
    $n = max(0, min(5, $n));
    return '<span class="stars">' . str_repeat('★', $n) . str_repeat('☆', 5 - $n) . '</span>';
}
