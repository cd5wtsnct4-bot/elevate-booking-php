<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$admin = requireAdmin();

$pendingCount = (int) db()->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending'")->fetchColumn();
$clientCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'client' AND status = 'active'")->fetchColumn();
$calendarCount = (int) db()->query('SELECT COUNT(*) FROM calendar_accounts')->fetchColumn();

$stmt = db()->query(
    "SELECT b.*, u.name AS client_name FROM bookings b
     JOIN users u ON u.id = b.user_id
     WHERE b.status = 'pending'
     ORDER BY b.booking_date ASC LIMIT 10"
);
$pending = $stmt->fetchAll();

$stmt = db()->query(
    "SELECT b.*, u.name AS client_name FROM bookings b
     JOIN users u ON u.id = b.user_id
     WHERE b.status = 'approved' AND b.booking_date >= CURDATE()
     ORDER BY b.booking_date ASC LIMIT 10"
);
$upcoming = $stmt->fetchAll();

$syncIssues = db()->query("SELECT * FROM calendar_accounts WHERE last_sync_error IS NOT NULL")->fetchAll();

$pageTitle = 'Admin dashboard';
require __DIR__ . '/../../includes/partials/header.php';
?>
<div class="page-intro">
    <h1>Dashboard</h1>
</div>

<div class="stat-row">
    <div class="stat-card"><span class="stat-card__num"><?= $pendingCount ?></span><span>Pending requests</span></div>
    <div class="stat-card"><span class="stat-card__num"><?= $clientCount ?></span><span>Active clients</span></div>
    <div class="stat-card"><span class="stat-card__num"><?= $calendarCount ?></span><span>Connected calendars</span></div>
</div>

<?php if ($syncIssues): ?>
<div class="alert alert--warning">
    <strong>Calendar sync issue.</strong>
    <?php foreach ($syncIssues as $cal): ?>
        <div><?= h($cal['label']) ?> (<?= h($cal['mailbox_email']) ?>): <?= h($cal['last_sync_error']) ?></div>
    <?php endforeach; ?>
    <a href="/admin/calendar-settings.php">Review calendar sync &rarr;</a>
</div>
<?php endif; ?>

<section class="admin-section">
    <h2>Pending requests</h2>
    <?php if (!$pending): ?>
        <p class="muted">Nothing waiting on you right now.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
        <thead><tr><th>Date</th><th>Client</th><th>Type</th><th>Notes</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pending as $b): ?>
            <tr>
                <td><?= h(formatDateHuman($b['booking_date'])) ?></td>
                <td><?= h($b['client_name']) ?></td>
                <td><?= $b['type'] === 'training' ? 'Training Session' : 'Meeting' ?></td>
                <td><?= h($b['notes'] ?? '') ?></td>
                <td><a href="/admin/bookings.php?status=pending">Review &rarr;</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<section class="admin-section">
    <h2>Upcoming confirmed sessions</h2>
    <?php if (!$upcoming): ?>
        <p class="muted">Nothing confirmed yet.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
        <thead><tr><th>Date</th><th>Client</th><th>Type</th></tr></thead>
        <tbody>
        <?php foreach ($upcoming as $b): ?>
            <tr>
                <td><?= h(formatDateHuman($b['booking_date'])) ?></td>
                <td><?= h($b['client_name']) ?></td>
                <td><?= $b['type'] === 'training' ? 'Training Session' : 'Meeting' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../../includes/partials/footer.php'; ?>
