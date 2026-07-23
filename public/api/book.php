<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../lib/Availability.php';

$user = currentUser();
if (!$user) {
    jsonResponse(['error' => 'Not authenticated'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$body = readJsonBody();

if (!csrfVerifyToken((string) ($body['csrf_token'] ?? ''))) {
    jsonResponse(['error' => 'Invalid or expired session. Please refresh the page.'], 403);
}

$date = (string) ($body['date'] ?? '');
$type = (string) ($body['type'] ?? '');
$notes = trim((string) ($body['notes'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
    jsonResponse(['error' => 'Invalid date.'], 400);
}
if (!in_array($type, ['meeting', 'training'], true)) {
    jsonResponse(['error' => 'Invalid booking type.'], 400);
}
if (strlen($notes) > 2000) {
    jsonResponse(['error' => 'Notes are too long.'], 400);
}

// Weekends are never bookable — enforced here (not just hidden client-side
// in calendar.js) so a direct API call can't bypass it.
$dayOfWeek = (int) date('w', strtotime($date));
if ($dayOfWeek === 0 || $dayOfWeek === 6) {
    jsonResponse(['error' => 'Weekends are not available for booking.'], 400);
}

$detail = Availability::detailForDate($date, $user['id']);

// Fail closed: if a connected calendar's live Graph check just failed, an
// 'open' status doesn't actually mean the date is free — it means we
// couldn't check. Refuse rather than risk double-booking something that's
// genuinely busy on the real Outlook calendar but invisible to us right now.
if (!$detail['graphOk']) {
    jsonResponse(['error' => "We can't verify live calendar availability right now, so new requests are paused. Please try again shortly, or contact us directly to book."], 503);
}
if ($detail['status'] !== 'open') {
    jsonResponse(['error' => 'That date is no longer available. Please choose another.'], 409);
}

$title = ($type === 'training' ? 'Training Session' : 'Meeting') . ' — ' . $user['name'];

$stmt = db()->prepare(
    'INSERT INTO bookings (user_id, type, booking_date, title, notes, status) VALUES (?, ?, ?, ?, ?, \'pending\')'
);
$stmt->execute([$user['id'], $type, $date, $title, $notes !== '' ? $notes : null]);

jsonResponse(['ok' => true, 'bookingId' => (int) db()->lastInsertId()]);
