<?php
$navUser = currentUser();
$here = $_SERVER['SCRIPT_NAME'] ?? '';
function navActive(string $path): string {
    global $here;
    return $here === $path ? ' class="active"' : '';
}
?>
<header class="navbar">
  <div class="container navbar-inner">
    <a class="brand" href="/"><?= logoMark() ?>FixMate</a>

    <button class="nav-toggle" type="button" aria-label="Toggle menu" aria-expanded="false">&#9776;</button>

    <ul class="nav-links">
      <li><a href="/services.php"<?= navActive('/services.php') ?>>Services</a></li>
      <li><a href="/about.php"<?= navActive('/about.php') ?>>About</a></li>

      <?php if ($navUser): ?>
        <li><a href="<?= e(dashboardPath($navUser)) ?>">Dashboard</a></li>
        <li><a href="/profile.php"<?= navActive('/profile.php') ?>><?= e($navUser['first_name'] !== '' ? $navUser['first_name'] : $navUser['username']) ?></a></li>
        <li>
          <form method="post" action="/logout.php">
            <?= csrfField() ?>
            <button type="submit" class="link-btn no-loading">Log out</button>
          </form>
        </li>
      <?php else: ?>
        <li><a href="/login.php"<?= navActive('/login.php') ?>>Log in</a></li>
        <li><a class="btn btn-primary btn-sm" href="/signup.php">Sign up</a></li>
      <?php endif; ?>
    </ul>
  </div>
</header>
