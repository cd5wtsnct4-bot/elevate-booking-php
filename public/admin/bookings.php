<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../lib/BookingSync.php';

$admin = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    $stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([$id]);
    $booking = $stmt->fetch();

    if (!$booking) {
        flash('error', 'Booking not found.');
        redirect(url('/admin/bookings.php'));
    }

    $warning = null;
    if ($action === 'approve') {
        $calendarAccountId = (int) ($_POST['calendar_account_id'] ?? 0);
        $warning = BookingSync::approve($booking, $calendarAccountId ?: 0, $admin['id']);
        flash('success', 'Booking approved.');
    } elseif ($action === 'decline') {
        $warning = BookingSync::decline($booking, $admin['id'], trim($_POST['note'] ?? '') ?: null);
        flash('success', 'Booking declined.');
    } elseif ($action === 'cancel') {
        $warning = BookingSync::cancel($booking, $admin['id']);
        flash('success', 'Booking cancelled.');
    } elseif ($action === 'delete') {
        $warning = BookingSync::delete($booking);
        flash('success', 'Booking deleted.');
    }

    if ($warning) {
        flash('error', $warning);
    }
    redirect(url('/admin/bookings.php?status=' . rawurlencode($_GET['status'] ?? 'pending')));
}

$statusFilter = $_GET['status'] ?? 'pending';
$validStatuses = ['pending', 'approved', 'declined', 'cancelled', 'all'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'pending';
}

$sql = "SELECT b.*, u.name AS client_name, u.email AS client_email, ca.label AS calendar_label
        FROM bookings b
        JOIN users u ON u.id = b.user_id
        LEFT JOIN calendar_accounts ca ON ca.id = b.calendar_account_id";
if ($statusFilter !== 'all') {
    $sql .= ' WHERE b.status = ' . db()->quote($statusFilter);
}
$sql .= ' ORDER BY b.booking_date DESC';
$bookings = db()->query($sql)->fetchAll();

$calendars = db()->query('SELECT * FROM calendar_accounts ORDER BY label')->fetchAll();

$pageTitle = 'Bookings';
require __DIR__ . '/../../includes/partials/header.php';
?>
<div class="page-intro page-intro--with-action">
    <h1>Bookings</h1>
    <a href="<?= url('/admin/booking-form.php') ?>" class="btn btn--primary">+ Add booking</a>
</div>

<nav class="tab-nav">
    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'declined' => 'Declined', 'cancelled' => 'Cancelled', 'all' => 'All'] as $key => $label): ?>
        <a href="<?= url('/admin/bookings.php?status=' . $key) ?>" class="<?= $statusFilter === $key ? 'is-active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$bookings): ?>
    <p class="muted">No bookings in this view.</p>
<?php else: ?>
<div class="table-scroll">
<table class="data-table">
    <thead><tr><th>Date</th><th>Client</th><th>Type</th><th>Status</th><th>Calendar</th><th>Notes</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($bookings as $b): ?>
        <tr>
            <td><?= h(formatDateHuman($b['booking_date'])) ?></td>
            <td><?= h($b['client_name']) ?><br><span class="muted"><?= h($b['client_email']) ?></span></td>
            <td><?= $b['type'] === 'training' ? 'Training Session' : 'Meeting' ?></td>
            <td><span class="status-pill status-pill--<?= h($b['status']) ?>"><?= h(ucfirst($b['status'])) ?></span></td>
            <td><?= h($b['calendar_label'] ?? '—') ?></td>
            <td><?= h($b['notes'] ?? '') ?></td>
            <td class="data-table__actions">
                <a href="<?= url('/admin/booking-form.php?id=' . (int) $b['id']) ?>">Edit</a>

                <?php if ($b['status'] === 'pending'): ?>
                <form method="post" class="inline-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                    <select name="calendar_account_id">
                        <option value="0">No calendar</option>
                        <?php foreach ($calendars as $cal): ?>
                            <option value="<?= (int) $cal['id'] ?>"><?= h($cal['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="link-btn link-btn--success">Approve</button>
                </form>
                <form method="post" class="inline-form" onsubmit="return confirm('Decline this request?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="decline">
                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                    <button type="submit" class="link-btn link-btn--danger">Decline</button>
                </form>
                <?php elseif ($b['status'] === 'approved'): ?>
                <form method="post" class="inline-form" onsubmit="return confirm('Cancel this confirmed booking? The calendar event will be removed.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                    <button type="submit" class="link-btn link-btn--danger">Cancel</button>
                </form>
                <?php endif; ?>

                <form method="post" class="inline-form" onsubmit="return confirm('Delete this booking permanently?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                    <button type="submit" class="link-btn link-btn--danger">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/partials/footer.php'; ?>
