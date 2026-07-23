<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$admin = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($id === $admin['id']) {
        flash('error', "You can't modify your own account from here.");
        redirect(url('/admin/clients.php'));
    }

    if ($action === 'toggle_status') {
        $stmt = db()->prepare("SELECT status FROM users WHERE id = ? AND role = 'client'");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $newStatus = $row['status'] === 'active' ? 'disabled' : 'active';
            db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
            flash('success', 'Client ' . ($newStatus === 'active' ? 'enabled' : 'disabled') . '.');
        }
    } elseif ($action === 'delete') {
        db()->prepare("DELETE FROM users WHERE id = ? AND role = 'client'")->execute([$id]);
        flash('success', 'Client deleted.');
    }

    redirect(url('/admin/clients.php'));
}

$clients = db()->query(
    "SELECT * FROM users WHERE role = 'client' ORDER BY created_at DESC"
)->fetchAll();

$pageTitle = 'Clients';
require __DIR__ . '/../../includes/partials/header.php';
?>
<div class="page-intro page-intro--with-action">
    <h1>Clients</h1>
    <a href="<?= url('/admin/client-form.php') ?>" class="btn btn--primary">+ Add client</a>
</div>

<?php if (!$clients): ?>
    <p class="muted">No clients yet. Add one to give them access to the booking calendar.</p>
<?php else: ?>
<div class="table-scroll">
<table class="data-table">
    <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Status</th><th>Added</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($clients as $c): ?>
        <tr>
            <td><?= h($c['name']) ?></td>
            <td><?= h($c['email']) ?></td>
            <td><?= h($c['phone'] ?? '—') ?></td>
            <td><span class="status-pill status-pill--<?= $c['status'] === 'active' ? 'approved' : 'declined' ?>"><?= h(ucfirst($c['status'])) ?></span></td>
            <td><?= h(date('j M Y', strtotime($c['created_at']))) ?></td>
            <td class="data-table__actions">
                <a href="<?= url('/admin/client-form.php?id=' . (int) $c['id']) ?>">Edit</a>
                <form method="post" class="inline-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <button type="submit" class="link-btn"><?= $c['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                </form>
                <form method="post" class="inline-form" onsubmit="return confirm('Delete this client permanently? Their booking history will also be deleted.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
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
