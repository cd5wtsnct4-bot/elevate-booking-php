<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../lib/BookingSync.php';

$admin = requireAdmin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : (isset($_POST['id']) ? (int) $_POST['id'] : null);
$booking = null;
if ($id) {
    $stmt = db()->prepare(
        'SELECT b.*, u.name AS client_name, u.email AS client_email FROM bookings b JOIN users u ON u.id = b.user_id WHERE b.id = ?'
    );
    $stmt->execute([$id]);
    $booking = $stmt->fetch() ?: null;
    if (!$booking) {
        flash('error', 'Booking not found.');
        redirect('/admin/bookings.php');
    }
}

$errors = [];

/** Applies a desired final status to a freshly-saved booking row. */
function applyDesiredStatus(array $row, string $desiredStatus, int $calendarAccountId, int $adminId, ?string $note): ?string
{
    switch ($desiredStatus) {
        case 'approved':
            return BookingSync::approve($row, $calendarAccountId, $adminId);
        case 'declined':
            return BookingSync::decline($row, $adminId, $note);
        case 'cancelled':
            return BookingSync::cancel($row, $adminId);
        default:
            db()->prepare('UPDATE bookings SET status = \'pending\', decided_by = NULL, decided_at = NULL WHERE id = ?')
                ->execute([$row['id']]);
            return null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $type = ($_POST['type'] ?? '') === 'training' ? 'training' : 'meeting';
    $date = (string) ($_POST['booking_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $desiredStatus = $_POST['status'] ?? 'pending';
    $calendarAccountId = (int) ($_POST['calendar_account_id'] ?? 0);
    $note = trim($_POST['decision_note'] ?? '') ?: null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $errors[] = 'A valid date is required.';
    }
    if (!in_array($desiredStatus, ['pending', 'approved', 'declined', 'cancelled'], true)) {
        $errors[] = 'Invalid status.';
    }

    $clientId = $booking['user_id'] ?? (int) ($_POST['user_id'] ?? 0);
    if (!$booking) {
        $clientStmt = db()->prepare("SELECT * FROM users WHERE id = ? AND role = 'client'");
        $clientStmt->execute([$clientId]);
        $clientRow = $clientStmt->fetch();
        if (!$clientRow) {
            $errors[] = 'Choose a client.';
        }
    }

    if (!$errors) {
        $title = ($type === 'training' ? 'Training Session' : 'Meeting') . ' — ' . ($booking['client_name'] ?? $clientRow['name']);

        if ($booking) {
            // Clear any existing calendar event before reapplying the
            // desired state — avoids leaving a stale/duplicate event behind
            // if the date, type, or target calendar changed.
            if ($booking['ms_event_id']) {
                BookingSync::cancel($booking, $admin['id']);
            }
            db()->prepare('UPDATE bookings SET type = ?, booking_date = ?, title = ?, notes = ? WHERE id = ?')
                ->execute([$type, $date, $title, $notes ?: null, $booking['id']]);

            $freshStmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
            $freshStmt->execute([$booking['id']]);
            $freshRow = $freshStmt->fetch();

            $warning = applyDesiredStatus($freshRow, $desiredStatus, $calendarAccountId, $admin['id'], $note);
            flash('success', 'Booking updated.');
        } else {
            db()->prepare(
                'INSERT INTO bookings (user_id, type, booking_date, title, notes, status) VALUES (?, ?, ?, ?, ?, \'pending\')'
            )->execute([$clientId, $type, $date, $title, $notes ?: null]);
            $newId = (int) db()->lastInsertId();

            $freshStmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
            $freshStmt->execute([$newId]);
            $freshRow = $freshStmt->fetch();

            $warning = applyDesiredStatus($freshRow, $desiredStatus, $calendarAccountId, $admin['id'], $note);
            flash('success', 'Booking created.');
        }

        if ($warning) {
            flash('error', $warning);
        }
        redirect('/admin/bookings.php?status=all');
    }
}

$clients = db()->query("SELECT id, name, email FROM users WHERE role = 'client' AND status = 'active' ORDER BY name")->fetchAll();
$calendars = db()->query('SELECT * FROM calendar_accounts ORDER BY label')->fetchAll();

$pageTitle = $booking ? 'Edit booking' : 'Add booking';
require __DIR__ . '/../../includes/partials/header.php';
?>
<div class="page-intro"><h1><?= $booking ? 'Edit booking' : 'Add a booking' ?></h1></div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert--error"><?= h($error) ?></div>
<?php endforeach; ?>

<form method="post" class="stacked-form">
    <?= csrfField() ?>
    <?php if ($booking): ?><input type="hidden" name="id" value="<?= (int) $booking['id'] ?>"><?php endif; ?>

    <?php if ($booking): ?>
        <p><strong>Client:</strong> <?= h($booking['client_name']) ?> (<?= h($booking['client_email']) ?>)</p>
    <?php else: ?>
        <label>Client
            <select name="user_id" required>
                <option value="">— Choose a client —</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= h($c['name']) ?> (<?= h($c['email']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>

    <label>Type
        <select name="type">
            <option value="meeting" <?= ($booking['type'] ?? '') === 'meeting' ? 'selected' : '' ?>>Meeting</option>
            <option value="training" <?= ($booking['type'] ?? '') === 'training' ? 'selected' : '' ?>>Training Session</option>
        </select>
    </label>
    <label>Date
        <input type="date" name="booking_date" required value="<?= h($booking['booking_date'] ?? '') ?>">
    </label>
    <label>Notes
        <textarea name="notes" rows="3"><?= h($booking['notes'] ?? '') ?></textarea>
    </label>
    <label>Status
        <select name="status" id="status-select">
            <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'declined' => 'Declined', 'cancelled' => 'Cancelled'] as $val => $label): ?>
                <option value="<?= $val ?>" <?= ($booking['status'] ?? 'approved') === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label id="calendar-select-wrap">Calendar to sync to (if approved)
        <select name="calendar_account_id">
            <option value="0">No calendar</option>
            <?php foreach ($calendars as $cal): ?>
                <option value="<?= (int) $cal['id'] ?>" <?= (int) ($booking['calendar_account_id'] ?? 0) === (int) $cal['id'] ? 'selected' : '' ?>><?= h($cal['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Decline/cancel note (optional)
        <input type="text" name="decision_note" value="<?= h($booking['decision_note'] ?? '') ?>">
    </label>

    <div class="booking-card__actions">
        <button type="submit" class="btn btn--primary"><?= $booking ? 'Save changes' : 'Create booking' ?></button>
        <a href="/admin/bookings.php?status=all" class="btn btn--ghost">Cancel</a>
    </div>
</form>
<?php require __DIR__ . '/../../includes/partials/footer.php'; ?>
