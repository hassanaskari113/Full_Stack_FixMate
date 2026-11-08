<?php
$fu = currentUser();
$cols = ['Explore' => [['/services.php', 'Services'], ['/about.php', 'About']]];
if (!$fu) {
    $cols['Customers'] = [['/signup.php', 'Post a request'], ['/login.php', 'Log in']];
    $cols['Providers'] = [['/signup.php?role=P', 'Join as a provider'], ['/login.php', 'Provider log in']];
} elseif ((int) $fu['is_admin'] === 1) {
    $cols['Admin'] = [['/admin/dashboard.php', 'Dashboard'], ['/admin/requests.php', 'Requests'], ['/admin/providers.php', 'Verification']];
} elseif ($fu['role'] === 'P') {
    $cols['Your work'] = [['/provider/requests.php', 'Matching requests'], ['/provider/offers.php', 'My offers'], ['/bookings.php', 'Bookings']];
} else {
    $cols['Your jobs'] = [['/customer/request_new.php', 'New request'], ['/customer/requests.php', 'My requests'], ['/bookings.php', 'My bookings']];
}
if ($fu) {
    $cols['Account'] = [['/profile.php', 'Profile settings']];
}
?>
</main>
<footer class="footer">
  <div class="container">
    <div class="footer-panel">
      <span class="footer-dots" aria-hidden="true"></span>
      <span class="tape" aria-hidden="true"></span>

      <div class="footer-top">
        <div class="footer-pitch">
          <a class="brand" href="/"><?= logoMark(34, true) ?>FixMate</a>
          <h2>Got something that needs fixing?</h2>
          <p>Post the job, compare quotes from approved providers, and book the one you trust.</p>
          <?php if ($fu): ?>
            <a class="btn btn-accent btn-lg" href="<?= e(dashboardPath($fu)) ?>">Go to my dashboard</a>
          <?php else: ?>
            <a class="btn btn-accent btn-lg" href="/signup.php">Post a request</a>
          <?php endif; ?>
        </div>

        <div class="footer-cols">
          <?php foreach ($cols as $title => $links): ?>
            <div>
              <h4><?= e($title) ?></h4>
              <ul>
                <?php foreach ($links as [$href, $label]): ?>
                  <li><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="footer-bottom">
        <span>&copy; <?= date('Y') ?> FixMate</span>
        <a href="#">Back to top</a>
      </div>
    </div>
  </div>
</footer>
<script src="/assets/js/app.js?v=<?= (int) @filemtime(BASE_PATH . '/public/assets/js/app.js') ?>"></script>
</body>
</html>
