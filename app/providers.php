<?php
declare(strict_types=1);

function getProviderProfile(int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM provider_profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function providerServiceIds(int $profileId): array
{
    $stmt = db()->prepare('SELECT service_id FROM provider_profile_services WHERE provider_profile_id = ?');
    $stmt->execute([$profileId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function providerAreaIds(int $profileId): array
{
    $stmt = db()->prepare('SELECT service_area_id FROM provider_profile_service_areas WHERE provider_profile_id = ?');
    $stmt->execute([$profileId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Services with their category names, for display. */
function providerServiceList(int $profileId): array
{
    $stmt = db()->prepare(
        'SELECT s.name, c.name AS category_name
         FROM provider_profile_services pps
         JOIN services s ON s.id = pps.service_id
         JOIN service_categories c ON c.id = s.category_id
         WHERE pps.provider_profile_id = ?
         ORDER BY c.name, s.name'
    );
    $stmt->execute([$profileId]);
    return $stmt->fetchAll();
}

function providerAreaNames(int $profileId): array
{
    $stmt = db()->prepare(
        'SELECT a.name
         FROM provider_profile_service_areas ppa
         JOIN service_areas a ON a.id = ppa.service_area_id
         WHERE ppa.provider_profile_id = ?
         ORDER BY a.name'
    );
    $stmt->execute([$profileId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function isApprovedProvider(?array $profile): bool
{
    return $profile !== null && $profile['verification_status'] === 'A';
}

/** Page guard: a provider who has created a profile. Returns the profile. */
function requireProviderProfile(): array
{
    requireRole('P');
    $profile = getProviderProfile((int) currentUser()['id']);
    if (!$profile) {
        flash('info', 'Please set up your provider profile first.');
        redirect('/provider/profile.php');
    }
    return $profile;
}

/** Page guard: an APPROVED provider. Returns the profile. */
function requireApprovedProvider(): array
{
    $profile = requireProviderProfile();
    if (!isApprovedProvider($profile)) {
        flash('warning', 'Your profile must be approved by an administrator before you can do this.');
        redirect('/provider/dashboard.php');
    }
    return $profile;
}
