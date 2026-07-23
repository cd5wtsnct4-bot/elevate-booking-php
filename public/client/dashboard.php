<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$user = requireLogin();

$stmt = db()->prepare(
    'SELECT * FROM bookings WHERE user_id = ? ORDER BY booking_date DESC LIMIT 25'
);
$stmt->execute([$user['id']]);
$myBookings = $stmt->fetchAll();

$pageTitle = 'Book a session';
$bodyClass = 'has-network-bg';
require __DIR__ . '/../../includes/partials/header.php';
?>
<div class="page-intro">
    <h1>Book a meeting or training session</h1>
    <p>Pick an open date on the calendar below. Your request is sent to Elevate SJC for approval — you'll see it marked <em>requested</em> until it's confirmed.</p>
</div>

<div class="calendar-layout">
    <section class="calendar-card">
        <div class="calendar-card__nav">
            <button type="button" id="cal-prev" class="btn btn--ghost" aria-label="Previous month">&larr;</button>
            <h2 id="cal-title"></h2>
            <button type="button" id="cal-next" class="btn btn--ghost" aria-label="Next month">&rarr;</button>
        </div>
        <div id="cal-sync-warning" class="alert alert--warning" hidden>
            Live calendar sync is temporarily unavailable — dates shown reflect manual bookings only.
        </div>
        <div class="calendar-grid" id="cal-grid" aria-live="polite"></div>
        <ul class="calendar-legend">
            <li><span class="dot dot--open"></span> Open</li>
            <li><span class="dot dot--requested-mine"></span> Your request</li>
            <li><span class="dot dot--requested"></span> Requested</li>
            <li><span class="dot dot--booked"></span> Unavailable</li>
            <li><span class="dot dot--weekend"></span> Weekend (not bookable)</li>
        </ul>
    </section>

    <section class="booking-card" id="booking-form-card" hidden>
        <h2>Request <span id="booking-date-label"></span></h2>
        <form id="booking-form" class="stacked-form">
            <label>Type
                <select name="type" id="booking-type" required>
                    <option value="meeting">Meeting</option>
                    <option value="training">Training Session (full day)</option>
                </select>
            </label>
            <label>Notes for Elevate SJC (optional)
                <textarea name="notes" id="booking-notes" rows="3" maxlength="2000"></textarea>
            </label>
            <div id="booking-form-error" class="alert alert--error" hidden></div>
            <div class="booking-card__actions">
                <button type="submit" class="btn btn--primary">Submit request</button>
                <button type="button" class="btn btn--ghost" id="booking-cancel">Cancel</button>
            </div>
        </form>
    </section>
</div>

<section class="my-bookings">
    <h2>Your requests</h2>
    <?php if (!$myBookings): ?>
        <p class="muted">You haven't requested anything yet.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
        <thead><tr><th>Date</th><th>Type</th><th>Status</th><th>Notes</th></tr></thead>
        <tbody>
        <?php foreach ($myBookings as $b): ?>
            <tr>
                <td><?= h(formatDateHuman($b['booking_date'])) ?></td>
                <td><?= $b['type'] === 'training' ? 'Training Session' : 'Meeting' ?></td>
                <td><span class="status-pill status-pill--<?= h($b['status']) ?>"><?= h(ucfirst($b['status'])) ?></span></td>
                <td><?= h($b['notes'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<script src="<?= url('/assets/js/calendar.js') ?>"></script>
<script>
ElevateCalendar.init({
    basePath: <?= json_encode(basePath()) ?>,
    gridEl: document.getElementById('cal-grid'),
    titleEl: document.getElementById('cal-title'),
    prevBtn: document.getElementById('cal-prev'),
    nextBtn: document.getElementById('cal-next'),
    syncWarningEl: document.getElementById('cal-sync-warning'),
    formCardEl: document.getElementById('booking-form-card'),
    formEl: document.getElementById('booking-form'),
    dateLabelEl: document.getElementById('booking-date-label'),
    cancelBtn: document.getElementById('booking-cancel'),
    errorEl: document.getElementById('booking-form-error'),
    selectableStatuses: ['open'],
    onBooked: function () { window.location.reload(); }
});
</script>
<?php require __DIR__ . '/../../includes/partials/footer.php'; ?>
