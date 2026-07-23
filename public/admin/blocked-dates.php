<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$admin = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete') {
        db()->prepare('DELETE FROM blocked_dates WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        flash('success', 'Blocked date removed.');
    } else {
        $date = (string) ($_POST['blocked_date'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $calendarAccountId = (int) ($_POST['calendar_account_id'] ?? 0);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            flash('error', 'A valid date is required.');
        } else {
            db()->prepare(
                'INSERT INTO blocked_dates (calendar_account_id, blocked_date, reason, created_by) VALUES (?, ?, ?, ?)'
            )->execute([$calendarAccountId ?: null, $date, $reason ?: null, $admin['id']]);
            flash('success', 'Date blocked.');
        }
    }
    redirect(url('/admin/blocked-dates.php'));
}

$blocked = db()->query(
    "SELECT bd.*, ca.label AS calendar_label FROM blocked_dates bd
     LEFT JOIN calendar_accounts ca ON ca.id = bd.calendar_account_id
     WHERE bd.blocked_date >= CURDATE()
     ORDER BY bd.blocked_date ASC"
)->fetchAll();

$calendars = db()->query('SELECT * FROM calendar_accounts ORDER BY label')->fetchAll();

$pageTitle = 'Blocked dates';
require __DIR__ . '/../../includes/partials/header.php';
?>
<div class="page-intro"><h1>Blocked dates</h1><p>Manually block a date (e.g. a holiday) across one or both calendars — clients won't be able to request it.</p></div>

<form method="post" class="stacked-form stacked-form--inline">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add">
    <label>Date
        <input type="date" name="blocked_date" required>
    </label>
    <label>Applies to
        <select name="calendar_account_id">
            <option value="0">Both calendars</option>
            <?php foreach ($calendars as $cal): ?>
                <option value="<?= (int) $cal['id'] ?>"><?= h($cal['label']) ?> only</option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Reason (optional)
        <input type="text" name="reason" placeholder="e.g. Public holiday">
    </label>
    <button type="submit" class="btn btn--primary">Block date</button>
</form>

<?php if (!$blocked): ?>
    <p class="muted">No upcoming blocked dates.</p>
<?php else: ?>
<div class="table-scroll">
<table class="data-table">
    <thead><tr><th>Date</th><th>Applies to</th><th>Reason</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($blocked as $b): ?>
        <tr>
            <td><?= h(formatDateHuman($b['blocked_date'])) ?></td>
            <td><?= h($b['calendar_label'] ?? 'Both calendars') ?></td>
            <td><?= h($b['reason'] ?? '') ?></td>
            <td>
                <form method="post" class="inline-form" onsubmit="return confirm('Remove this block?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                    <button type="submit" class="link-btn link-btn--danger">Remove</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/partials/footer.php'; ?>
