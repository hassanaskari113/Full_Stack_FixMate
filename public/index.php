<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user       = currentUser();
$isCustomer = $user && (int) $user['is_admin'] !== 1 && $user['role'] === 'C';

$rows = db()->query(
    'SELECT c.id AS cat_id, c.name AS cat_name, s.name AS service_name
     FROM service_categories c LEFT JOIN services s ON s.category_id = c.id
     ORDER BY c.id, s.name'
)->fetchAll();

$cats = [];
foreach ($rows as $r) {
    $id = (int) $r['cat_id'];
    $cats[$id]['id'] = $id;
    $cats[$id]['name'] = $r['cat_name'];
    $cats[$id]['services'] = $cats[$id]['services'] ?? [];
    if ($r['service_name'] !== null) {
        $cats[$id]['services'][] = $r['service_name'];
    }
}
$cats     = array_values($cats);
$pickCats = array_slice($cats, 0, 8);
$jobs     = [];
foreach ($pickCats as $c) {
    $jobs[$c['id']] = array_slice($c['services'], 0, 4);
}

$ctaBase  = $isCustomer ? '/customer/request_new.php' : '';
$ctaHref  = $isCustomer ? '/customer/request_new.php' : ($user ? dashboardPath($user) : '/signup.php');
$ctaLabel = $isCustomer ? 'Post this job' : ($user ? 'Go to my dashboard' : 'Sign up to post this job');

$pageTitle = null;
require_once BASE_PATH . '/includes/header.php';
?>

<section class="hero">
  <div class="container hero-grid">
    <div class="hero-copy">
      <h1><span>Post the job.</span><span>Compare quotes.</span><span>Book the pro.</span></h1>
      <p class="lead">Describe what needs fixing and set your budget. Approved providers in your area reply with a price and an arrival time, and you pick the one you like.</p>
      <div class="hero-actions">
        <?php if ($user): ?>
          <a class="btn btn-primary btn-lg" href="<?= e(dashboardPath($user)) ?>">Go to my dashboard</a>
        <?php else: ?>
          <a class="btn btn-primary btn-lg" href="/signup.php">Post a request</a>
          <a class="btn btn-outline btn-lg" href="/signup.php?role=P">Work as a provider</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="hero-art">
      <div class="ticket stack hero-ticket" id="heroTicket"
           data-base="<?= e($ctaBase) ?>" data-jobs="<?= e(json_encode($jobs)) ?>">
        <span class="tape" aria-hidden="true"></span>
        <div class="ticket-main">
          <h2>What needs fixing?</h2>
          <?php if ($pickCats): ?>
            <div class="chip-picker" role="radiogroup" aria-label="Choose a category">
              <?php foreach ($pickCats as $c): ?>
                <button type="button" class="pick no-loading" role="radio" aria-checked="false"
                        data-id="<?= (int) $c['id'] ?>" data-name="<?= e($c['name']) ?>"><?= e($c['name']) ?></button>
              <?php endforeach; ?>
            </div>
            <p class="ticket-jobs" data-jobs-line aria-live="polite">Pick a category to see the kind of jobs providers handle.</p>
          <?php else: ?>
            <p class="ticket-jobs">Service categories are being set up. Check back shortly.</p>
          <?php endif; ?>
        </div>
        <div class="ticket-stub">
          <div>
            <span class="stub-label">Your request</span>
            <strong class="stub-value" data-selected>No category chosen yet</strong>
          </div>
          <a class="btn btn-primary btn-block" data-cta href="<?= e($ctaHref) ?>"><?= e($ctaLabel) ?></a>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section-title">
      <h2>From a broken tap to a booked plumber</h2>
      <p>Three steps, and you stay in control of who you hire and what you pay.</p>
    </div>
    <div class="steps">
      <div class="step">
        <div class="step-num">1</div>
        <h3>Post the job</h3>
        <p>Pick a category, describe the work, set a budget range and a deadline, and add photos if they help.</p>
      </div>
      <div class="step">
        <div class="step-num">2</div>
        <h3>Compare quotes</h3>
        <p>Approved providers who cover your area and offer that service send you a price and arrival time.</p>
      </div>
      <div class="step">
        <div class="step-num">3</div>
        <h3>Book and review</h3>
        <p>Accept one quote to confirm the booking. When the work is done, rate it and add photos.</p>
      </div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:.5rem">
  <div class="container">
    <div class="section-title">
      <h2>Jobs providers take on</h2>
      <p>Browse the categories. Each one lists the services you can request.</p>
    </div>

    <?php if ($cats): ?>
      <div class="dir">
        <?php foreach (array_slice($cats, 0, 6) as $c): $n = count($c['services']); ?>
          <a class="dir-row" href="/services.php#cat-<?= (int) $c['id'] ?>">
            <span class="dir-name"><?= e($c['name']) ?></span>
            <span class="service-chips">
              <?php foreach (array_slice($c['services'], 0, 4) as $s): ?><span class="chip"><?= e($s) ?></span><?php endforeach; ?>
            </span>
            <span class="dir-count"><?= $n ?> service<?= $n === 1 ? '' : 's' ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <p style="margin-top:1.25rem"><a class="btn btn-outline" href="/services.php">See every service</a></p>
    <?php else: ?>
      <div class="card empty-state">
        <h3>No categories yet</h3>
        <p>Service categories appear here once an administrator adds them.</p>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if (!$user): ?>
<section class="section" style="padding-top:1rem">
  <div class="container">
    <div class="band">
      <div>
        <h2>Know a trade? Take the jobs near you.</h2>
        <p>Create a provider profile, get approved, and send quotes on requests that fit your skills.</p>
        <a class="btn btn-accent btn-lg" href="/signup.php?role=P">Join as a provider</a>
      </div>
      <ul class="band-list">
        <li><span class="tick"><?= icon('check', 16) ?></span><span>Requests are matched to the services you offer and the areas you cover.</span></li>
        <li><span class="tick"><?= icon('check', 16) ?></span><span>You set the price and the arrival time on every quote.</span></li>
        <li><span class="tick"><?= icon('check', 16) ?></span><span>Reviews from finished jobs build your profile.</span></li>
      </ul>
    </div>
  </div>
</section>
<?php endif; ?>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
