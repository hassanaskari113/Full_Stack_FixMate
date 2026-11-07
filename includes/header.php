<?php
// Usage in a page:  $pageTitle = 'Log in';  require_once BASE_PATH . '/includes/header.php';
$appName   = config('name');
$fullTitle = isset($pageTitle) ? $pageTitle . ' · ' . $appName : $appName . ' · Post the job, compare quotes, book the pro';
$ver = fn(string $f): int => (int) @filemtime(BASE_PATH . '/public' . $f);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#EDF0F6">
  <link rel="icon" type="image/svg+xml" href="/assets/images/logo.svg">
  <title><?= e($fullTitle) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,100..900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/style.css?v=<?= $ver('/assets/css/style.css') ?>">
</head>
<body>
<?php require BASE_PATH . '/includes/navbar.php'; ?>
<div class="toasts" aria-live="polite"><?php renderFlashes(); ?></div>
<main class="main">
