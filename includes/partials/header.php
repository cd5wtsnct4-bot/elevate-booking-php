<?php
/**
 * Expects (optionally) before include:
 *   $pageTitle   string
 *   $bodyClass   string  extra class on <body>, e.g. 'has-hero'
 */
$user = currentUser();
$pageTitle = $pageTitle ?? APP_NAME;
$bodyClass = $bodyClass ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= h(csrfToken()) ?>">
<title><?= h($pageTitle) ?> — <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="icon" href="/assets/images/logo.png">
</head>
<body class="<?= h($bodyClass) ?>">
<header class="site-header">
    <div class="site-header__inner">
        <a href="<?= $user ? ($user['role'] === 'admin' ? '/admin/dashboard.php' : '/client/dashboard.php') : '/index.php' ?>" class="brand">
            <img src="/assets/images/logo.png" alt="Elevate SJC">
        </a>
        <?php if ($user): ?>
        <nav class="main-nav">
            <?php if ($user['role'] === 'admin'): ?>
                <a href="/admin/dashboard.php">Dashboard</a>
                <a href="/admin/bookings.php">Bookings</a>
                <a href="/admin/clients.php">Clients</a>
                <a href="/admin/blocked-dates.php">Blocked Dates</a>
                <a href="/admin/calendar-settings.php">Calendar Sync</a>
            <?php else: ?>
                <a href="/client/dashboard.php">Calendar</a>
            <?php endif; ?>
            <span class="main-nav__user">Signed in as <?= h($user['name']) ?></span>
            <a href="/logout.php" class="main-nav__logout">Log out</a>
        </nav>
        <?php endif; ?>
    </div>
</header>
<main class="site-main">
<?php
$flashSuccess = flash('success');
$flashError = flash('error');
if ($flashSuccess): ?>
    <div class="alert alert--success"><?= h($flashSuccess) ?></div>
<?php endif;
if ($flashError): ?>
    <div class="alert alert--error"><?= h($flashError) ?></div>
<?php endif; ?>
