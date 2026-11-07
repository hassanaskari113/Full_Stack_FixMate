<?php
$customerNavActive = $customerNavActive ?? '';
$customerLinks = [
    'dashboard' => ['/customer/dashboard.php',   'Dashboard'],
    'new'       => ['/customer/request_new.php', 'New request'],
    'requests'  => ['/customer/requests.php',    'My requests'],
    'bookings'  => ['/bookings.php',             'My bookings'],
    'reviews'   => ['/customer/reviews.php',     'My reviews'],
];
?>
<nav class="subnav" aria-label="Customer navigation">
  <div class="container">
    <?php foreach ($customerLinks as $key => [$href, $label]): ?>
      <a href="<?= e($href) ?>"<?= $customerNavActive === $key ? ' class="active"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</nav>
