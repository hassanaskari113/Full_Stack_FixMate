</main>
<footer class="footer">
  <div class="container">
    <div class="footer-grid">
      <div>
        <a class="brand" href="/" style="margin-bottom:.9rem"><?= logoMark(32) ?>FixMate</a>
        <p style="max-width:26rem">Post the job, compare quotes from approved providers near you, and book the one you trust.</p>
      </div>
      <div>
        <h4>Explore</h4>
        <ul>
          <li><a href="/services.php">Services</a></li>
          <li><a href="/about.php">About</a></li>
        </ul>
      </div>
      <div>
        <h4>Account</h4>
        <ul>
          <li><a href="/login.php">Log in</a></li>
          <li><a href="/signup.php">Sign up</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">&copy; <?= date('Y') ?> FixMate</div>
  </div>
</footer>
<script src="/assets/js/app.js?v=<?= (int) @filemtime(BASE_PATH . '/public/assets/js/app.js') ?>"></script>
</body>
</html>
