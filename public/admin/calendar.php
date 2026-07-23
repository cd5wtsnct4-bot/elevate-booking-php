<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../lib/BookingSync.php';

$admin = requireAdmin();

$year = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));
if ($month < 1 || $month > 12) {
    $month = (int) date('n');
}
$selectedDate = $_GET['date'] ?? null;
if ($selectedDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = null;
}

function calendarQueryString(int $year, int $month, ?string $date = null): string
{
    $params = ['year' => $year, 'month' => $month];
    if ($date) {
        $params['date'] = $date;
    }
    return http_build_query($params);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    $stmt = db()->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([$id]);
    $booking = $stmt->fetch();

    if (!$booking) {
        flash('error', 'Booking not found.');
    } else {
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
    }

    redirect(url('/admin/calendar.php?' . calendarQueryString($year, $month, $selectedDate)));
}

$start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
$end = $start->modify('last day of this month');
$startIso = $start->format('Y-m-d');
$endIso = $end->format('Y-m-d');
$todayIso = (new DateTimeImmutable('today'))->format('Y-m-d');

$stmt = db()->prepare('SELECT blocked_date, reason FROM blocked_dates WHERE blocked_date BETWEEN ? AND ?');
$stmt->execute([$startIso, $endIso]);
$blockedByDate = [];
foreach ($stmt->fetchAll() as $row) {
    $blockedByDate[$row['blocked_date']] = $row['reason'];
}

$stmt = db()->prepare(
    'SELECT b.*, u.name AS client_name, u.email AS client_email, ca.label AS calendar_label
     FROM bookings b
     JOIN users u ON u.id = b.user_id
     LEFT JOIN calendar_accounts ca ON ca.id = b.calendar_account_id
     WHERE b.booking_date BETWEEN ? AND ?
     ORDER BY b.booking_date ASC, b.created_at ASC'
);
$stmt->execute([$startIso, $endIso]);
$monthBookings = $stmt->fetchAll();

$daySummary = [];
for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
    $daySummary[$d->format('Y-m-d')] = ['pending' => 0, 'approved' => 0, 'declined' => 0, 'cancelled' => 0];
}
foreach ($monthBookings as $b) {
    if (isset($daySummary[$b['booking_date']])) {
        $daySummary[$b['booking_date']][$b['status']]++;
    }
}

$calendars = db()->query('SELECT * FROM calendar_accounts ORDER BY label')->fetchAll();

$displayedBookings = $selectedDate
    ? array_values(array_filter($monthBookings, fn ($b) => $b['booking_date'] === $selectedDate))
    : $monthBookings;

$prev = $start->modify('-1 month');
$next = $start->modify('+1 month');

$pageTitle = 'Calendar';
require __DIR__ . '/../../includes/partials/header.php';
?>
<div class="page-intro"><h1>Calendar</h1><p>A month-at-a-glance view of every booking and blocked date. Click a day to see its details below.</p></div>

<section class="admin-calendar-card">
    <div class="calendar-card__nav">
        <a href="<?= url('/admin/calendar.php?' . calendarQueryString((int) $prev->format('Y'), (int) $prev->format('n'))) ?>" class="btn btn--ghost">&larr;</a>
        <h2><?= h($start->format('F Y')) ?></h2>
        <a href="<?= url('/admin/calendar.php?' . calendarQueryString((int) $next->format('Y'), (int) $next->format('n'))) ?>" class="btn btn--ghost">&rarr;</a>
    </div>

    <div class="admin-calendar-grid">
        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $wd): ?>
            <div class="admin-calendar-grid__weekday"><?= $wd ?></div>
        <?php endforeach; ?>

        <?php
        $leadingBlanks = (int) $start->format('w');
        for ($i = 0; $i < $leadingBlanks; $i++): ?>
            <div class="admin-calendar-day admin-calendar-day--empty"></div>
        <?php endfor; ?>

        <?php foreach ($daySummary as $iso => $counts): ?>
            <?php
            $dayOfWeek = (int) date('w', strtotime($iso));
            $isWeekend = $dayOfWeek === 0 || $dayOfWeek === 6;
            $isBlocked = isset($blockedByDate[$iso]);
            $isPast = $iso < $todayIso;
            $isSelected = $selectedDate === $iso;
            $isToday = $iso === $todayIso;
            $hasAny = $isBlocked || array_sum($counts) > 0;
            ?>
            <?php if ($isWeekend): ?>
                <!-- Weekends are never bookable, so they're not click-selectable here either — shown for context only. -->
                <div class="admin-calendar-day admin-calendar-day--weekend">
                    <span class="admin-calendar-day__num"><?= (int) substr($iso, 8, 2) ?></span>
                </div>
            <?php else: ?>
                <?php
                $classes = ['admin-calendar-day'];
                if ($isPast) $classes[] = 'admin-calendar-day--past';
                if ($isSelected) $classes[] = 'admin-calendar-day--selected';
                if ($isToday) $classes[] = 'admin-calendar-day--today';
                ?>
                <a href="<?= url('/admin/calendar.php?' . calendarQueryString($year, $month, $iso)) ?>" class="<?= implode(' ', $classes) ?>">
                    <span class="admin-calendar-day__num"><?= (int) substr($iso, 8, 2) ?></span>
                    <?php if ($isBlocked): ?>
                        <span class="admin-calendar-day__tag admin-calendar-day__tag--blocked">Blocked</span>
                    <?php endif; ?>
                    <?php foreach (['approved' => 'success', 'pending' => 'warning', 'declined' => 'danger', 'cancelled' => 'muted'] as $status => $tone): ?>
                        <?php if ($counts[$status] > 0): ?>
                            <span class="admin-calendar-day__tag admin-calendar-day__tag--<?= $tone ?>"><?= $counts[$status] ?> <?= h(ucfirst($status)) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (!$hasAny && !$isPast): ?>
                        <span class="admin-calendar-day__tag admin-calendar-day__tag--available">Available</span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <ul class="calendar-legend">
        <li><span class="dot" style="background:var(--white);border-color:var(--success)"></span> Available</li>
        <li><span class="dot" style="background:var(--success-bg);border-color:var(--success)"></span> Approved</li>
        <li><span class="dot" style="background:var(--warning-bg);border-color:var(--warning)"></span> Pending</li>
        <li><span class="dot" style="background:var(--danger-bg);border-color:var(--danger)"></span> Declined</li>
        <li><span class="dot" style="background:var(--dark-gray)"></span> Blocked</li>
        <li><span class="dot" style="background:var(--light-gray)"></span> Weekend (not bookable)</li>
    </ul>
</section>

<section class="admin-section">
    <div class="page-intro page-intro--with-action">
        <h2><?= $selectedDate ? h(formatDateHuman($selectedDate)) : 'All bookings this month' ?></h2>
        <?php if ($selectedDate): ?>
            <a href="<?= url('/admin/calendar.php?' . calendarQueryString($year, $month)) ?>" class="btn btn--ghost">Show whole month</a>
        <?php endif; ?>
    </div>

    <?php if ($selectedDate && isset($blockedByDate[$selectedDate])): ?>
        <div class="alert alert--warning">
            This date is manually blocked<?= $blockedByDate[$selectedDate] ? ': ' . h($blockedByDate[$selectedDate]) : '.' ?>
        </div>
    <?php endif; ?>

    <?php if (!$displayedBookings): ?>
        <p class="muted">No bookings <?= $selectedDate ? 'on this date' : 'this month' ?>.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
        <thead><tr><th>Date</th><th>Client</th><th>Type</th><th>Status</th><th>Calendar</th><th>Notes</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($displayedBookings as $b): ?>
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
</section>
<?php require __DIR__ . '/../../includes/partials/footer.php'; ?>
