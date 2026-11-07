<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$pageTitle = 'About';
require_once BASE_PATH . '/includes/header.php';
?>
<section class="section">
  <div class="container">
    <div class="section-title" style="max-width:46rem">
      <h1 style="font-size:clamp(2.2rem,5vw,3.6rem);font-stretch:114%;letter-spacing:-.035em;line-height:1.02">A marketplace with rules that protect both sides.</h1>
      <p>FixMate connects people who need a job done with providers who can do it. The rules below are built into the system, not left to chance.</p>
    </div>

    <div class="principles">
      <div class="principle">
        <h3>Customers stay in charge</h3>
        <p>You set the budget and deadline, see every quote side by side, and accept exactly one. Accepting closes the request and rejects the rest.</p>
      </div>
      <div class="principle">
        <h3>Providers are checked first</h3>
        <p>An administrator approves every provider profile. Only approved providers can send quotes, and only on requests that match their services and areas.</p>
      </div>
      <div class="principle">
        <h3>Reviews come from real jobs</h3>
        <p>Only the customer of a completed booking can leave a review, and only once. Ratings and photos reflect work that actually happened.</p>
      </div>
    </div>
  </div>
</section>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
