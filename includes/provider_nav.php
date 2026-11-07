<?php
$providerNavActive = $providerNavActive ?? '';
$providerLinks = [
    'dashboard' => ['/provider/dashboard.php', 'Dashboard'],
    'requests'  => ['/provider/requests.php',  'Matching requests'],
    'offers'    => ['/provider/offers.php',    'My offers'],
    'bookings'  => ['/bookings.php',           'Bookings'],
    'profile'   => ['/provider/profile.php',   'My profile'],
];
?>
<nav class="subnav" aria-label="Provider navigation">
  <div class="container">
    <?php foreach ($providerLinks as $key => [$href, $label]): ?>
      <a href="<?= e($href) ?>"<?= $providerNavActive === $key ? ' class="active"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</nav>
