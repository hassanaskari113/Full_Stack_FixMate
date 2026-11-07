<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once BASE_PATH . '/app/providers.php';
requireRole('P');

$user    = currentUser();
$profile = getProviderProfile((int) $user['id']);
$isNew   = $profile === null;

$allServices = db()->query(
    'SELECT s.id, s.name, c.name AS category_name
     FROM services s JOIN service_categories c ON c.id = s.category_id
     ORDER BY c.name, s.name'
)->fetchAll();
$allAreas = db()->query('SELECT id, name FROM service_areas ORDER BY name')->fetchAll();

$validServiceIds = array_map('intval', array_column($allServices, 'id'));
$validAreaIds    = array_map('intval', array_column($allAreas, 'id'));

$errors             = [];
$bio                = $profile['bio'] ?? '';
$experience         = $profile ? (string) $profile['experience'] : '';
$selectedServices   = $profile ? providerServiceIds((int) $profile['id']) : [];
$selectedAreas      = $profile ? providerAreaIds((int) $profile['id']) : [];

if (isPost()) {
    verifyCsrf();

    $bio        = trim((string) ($_POST['bio'] ?? ''));
    $experience = trim((string) ($_POST['experience'] ?? ''));
    $selectedServices = cleanIdList($_POST['services'] ?? [], $validServiceIds);
    $selectedAreas    = cleanIdList($_POST['areas'] ?? [], $validAreaIds);

    if ($experience === '' || !ctype_digit($experience) || (int) $experience > 60) {
        $errors['experience'] = 'Enter your experience as whole years (0 to 60).';
    }
    if (mb_strlen($bio) > 1000) {
        $errors['bio'] = 'Your bio must be 1000 characters or fewer.';
    }
    if (!$selectedServices) {
        $errors['services'] = 'Select at least one service you offer.';
    }
    if (!$selectedAreas) {
        $errors['areas'] = 'Select at least one area you serve.';
    }

    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();

            $resubmitted = false;
            if ($isNew) {
                $pdo->prepare('INSERT INTO provider_profiles (user_id, bio, experience) VALUES (?, ?, ?)')
                    ->execute([$user['id'], $bio, (int) $experience]);
                $profileId = (int) $pdo->lastInsertId();
            } else {
                $profileId = (int) $profile['id'];
                $status = $profile['verification_status'];
                if ($status === 'R') {              // a rejected provider resubmits by saving
                    $status = 'P';
                    $resubmitted = true;
                }
                $pdo->prepare('UPDATE provider_profiles SET bio = ?, experience = ?, verification_status = ? WHERE id = ?')
                    ->execute([$bio, (int) $experience, $status, $profileId]);
            }

            $pdo->prepare('DELETE FROM provider_profile_services WHERE provider_profile_id = ?')->execute([$profileId]);
            $pdo->prepare('DELETE FROM provider_profile_service_areas WHERE provider_profile_id = ?')->execute([$profileId]);

            $ins = $pdo->prepare('INSERT INTO provider_profile_services (provider_profile_id, service_id) VALUES (?, ?)');
            foreach ($selectedServices as $sid) { $ins->execute([$profileId, $sid]); }
            $ins = $pdo->prepare('INSERT INTO provider_profile_service_areas (provider_profile_id, service_area_id) VALUES (?, ?)');
            foreach ($selectedAreas as $aid) { $ins->execute([$profileId, $aid]); }

            $pdo->commit();

            if ($isNew) {
                flash('success', 'Profile submitted! An administrator will review it shortly.');
            } elseif ($resubmitted) {
                flash('success', 'Profile updated and resubmitted for verification.');
            } else {
                flash('success', 'Profile updated.');
            }
            redirect('/provider/dashboard.php');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Provider profile save failed: ' . $ex->getMessage());
            $errors['general'] = 'Something went wrong while saving. Please try again.';
        }
    }
}

$servicesByCategory = [];
foreach ($allServices as $s) {
    $servicesByCategory[$s['category_name']][] = $s;
}

$pageTitle = $isNew ? 'Set up your profile' : 'Edit profile';
require_once BASE_PATH . '/includes/header.php';
$providerNavActive = 'profile';
require BASE_PATH . '/includes/provider_nav.php';
?>
<div class="container section" style="max-width:820px">
  <h1><?= $isNew ? 'Set up your provider profile' : 'Edit your provider profile' ?></h1>

  <?php if ($isNew): ?>
    <p class="muted">Tell customers about yourself and choose what you offer. An administrator reviews every profile before you can send offers.</p>
  <?php elseif ($profile['verification_status'] === 'R'): ?>
    <div class="alert alert-warning">Your profile was rejected. Update it and save to resubmit it for review.</div>
  <?php elseif ($profile['verification_status'] === 'A'): ?>
    <div class="alert alert-info">Your profile is approved. Changes to your services and areas apply immediately.</div>
  <?php endif; ?>

  <?php if (isset($errors['general'])): ?>
    <div class="alert alert-error"><?= e($errors['general']) ?></div>
  <?php endif; ?>

  <?php if (!$allServices || !$allAreas): ?>
    <div class="card empty-state">
      <h3>Not ready yet</h3>
      <p>An administrator has not added any <?= !$allServices ? 'services' : 'service areas' ?> yet. Please check back soon.</p>
    </div>
  <?php else: ?>
  <form method="post" novalidate>
    <?= csrfField() ?>

    <div class="card mb-1">
      <h3>About you</h3>
      <div class="form-group">
        <label for="experience">Years of experience</label>
        <input type="number" id="experience" name="experience" min="0" max="60" step="1" required
               class="<?= isset($errors['experience']) ? 'input-error' : '' ?>" value="<?= e($experience) ?>" style="max-width:160px">
        <?= fieldError($errors, 'experience') ?>
      </div>
      <div class="form-group">
        <label for="bio">Bio <span class="muted">(optional)</span></label>
        <textarea id="bio" name="bio" maxlength="1000" placeholder="Describe your skills, tools and the kind of jobs you do best."
                  class="<?= isset($errors['bio']) ? 'input-error' : '' ?>"><?= e($bio) ?></textarea>
        <?= fieldError($errors, 'bio') ?>
      </div>
    </div>

    <div class="card mb-1">
      <h3>Services you offer</h3>
      <p class="muted">Choose every service you can do. You will only see requests in the matching categories.</p>
      <?= fieldError($errors, 'services') ?>
      <?php foreach ($servicesByCategory as $catName => $services): ?>
        <div class="check-group">
          <h4><?= e($catName) ?></h4>
          <div class="check-grid">
            <?php foreach ($services as $s): ?>
              <label class="check-item">
                <input type="checkbox" name="services[]" value="<?= (int) $s['id'] ?>" <?= in_array((int) $s['id'], $selectedServices, true) ? 'checked' : '' ?>>
                <?= e($s['name']) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card mb-1">
      <h3>Areas you serve</h3>
      <p class="muted">Customers in these areas can find you.</p>
      <?= fieldError($errors, 'areas') ?>
      <div class="check-grid">
        <?php foreach ($allAreas as $a): ?>
          <label class="check-item">
            <input type="checkbox" name="areas[]" value="<?= (int) $a['id'] ?>" <?= in_array((int) $a['id'], $selectedAreas, true) ? 'checked' : '' ?>>
            <?= e($a['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg">
      <?= $isNew ? 'Submit for verification' : ($profile['verification_status'] === 'R' ? 'Save and resubmit' : 'Save changes') ?>
    </button>
    <a class="btn btn-outline btn-lg" href="/provider/dashboard.php">Cancel</a>
  </form>
  <?php endif; ?>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
