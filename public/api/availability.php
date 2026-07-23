<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../lib/Availability.php';

$user = currentUser();
if (!$user) {
    jsonResponse(['error' => 'Not authenticated'], 401);
}

$year = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));

if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
    jsonResponse(['error' => 'Invalid year/month'], 400);
}

$result = Availability::forMonth($year, $month, $user['id']);

jsonResponse([
    'year' => $year,
    'month' => $month,
    'days' => $result['days'],
    'calendarSyncOk' => $result['graphOk'],
]);
