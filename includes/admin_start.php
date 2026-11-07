<?php
// Opens the admin layout (sidebar + content column).
$adminActive = $adminActive ?? '';
$adminLinks = [
    'dashboard'  => ['/admin/dashboard.php',  'Dashboard'],
    'users'      => ['/admin/users.php',      'Users'],
    'providers'  => ['/admin/providers.php',  'Provider verification'],
    'categories' => ['/admin/categories.php', 'Categories'],
    'services'   => ['/admin/services.php',   'Services'],
    'areas'      => ['/admin/areas.php',      'Service areas'],
    'requests'   => ['/admin/requests.php',   'Requests'],
    'offers'     => ['/admin/offers.php',     'Offers'],
    'bookings'   => ['/admin/bookings.php',   'Bookings'],
    'reviews'    => ['/admin/reviews.php',    'Reviews'],
];
?>
<div class="container admin-layout">
  <aside class="admin-sidebar">
    <p class="sidebar-title">Administration</p>
    <?php foreach ($adminLinks as $key => [$href, $label]): ?>
      <a href="<?= e($href) ?>"<?= $adminActive === $key ? ' class="active"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </aside>
  <section class="admin-content">
