<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../lib/Mailer.php';

$admin = requireAdmin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : (isset($_POST['id']) ? (int) $_POST['id'] : null);
$client = null;
if ($id) {
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND role = 'client'");
    $stmt->execute([$id]);
    $client = $stmt->fetch() ?: null;
    if (!$client) {
        flash('error', 'Client not found.');
        redirect(url('/admin/clients.php'));
    }
}

$errors = [];
$setupLinkInfo = null;

function buildSetupUrl(string $token): string
{
    return APP_BASE_URL . '/set-password.php?token=' . $token;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'resend_link' && $client) {
        $token = createPasswordSetupToken((int) $client['id']);
        $url = buildSetupUrl($token);
        $emailed = sendPasswordSetupEmail($client['email'], $client['name'], $url);
        $setupLinkInfo = ['url' => $url, 'emailed' => $emailed, 'name' => $client['name'], 'email' => $client['email']];
    } elseif ($action === 'save') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $status = ($_POST['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active';

        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if (!isValidEmail($email)) {
            $errors[] = 'A valid email is required.';
        }

        if (!$errors) {
            $dupStmt = db()->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $dupStmt->execute([strtolower($email), $client['id'] ?? 0]);
            if ($dupStmt->fetch()) {
                $errors[] = 'Another account already uses that email.';
            }
        }

        if (!$errors && $client) {
            db()->prepare('UPDATE users SET name = ?, email = ?, phone = ?, status = ? WHERE id = ?')
                ->execute([$name, strtolower($email), $phone ?: null, $status, $client['id']]);
            flash('success', 'Client updated.');
            redirect(url('/admin/clients.php'));
        } elseif (!$errors) {
            $stmt = db()->prepare(
                'INSERT INTO users (name, email, phone, role, status, must_change_password, created_by) VALUES (?, ?, ?, \'client\', \'active\', 1, ?)'
            );
            $stmt->execute([$name, strtolower($email), $phone ?: null, $admin['id']]);
            $newId = (int) db()->lastInsertId();

            $token = createPasswordSetupToken($newId);
            $url = buildSetupUrl($token);
            $emailed = sendPasswordSetupEmail($email, $name, $url);
            $setupLinkInfo = ['url' => $url, 'emailed' => $emailed, 'name' => $name, 'email' => $email];
            $client = ['id' => $newId, 'name' => $name, 'email' => strtolower($email), 'phone' => $phone, 'status' => 'active'];
        }
    }
}

$pageTitle = $client ? 'Edit client' : 'Add client';
require __DIR__ . '/../../includes/partials/header.php';
?>
<div class="page-intro">
    <h1><?= $client ? 'Edit client' : 'Add a new client' ?></h1>
</div>

<?php if ($setupLinkInfo): ?>
<div class="alert alert--success">
    <p><strong><?= h($setupLinkInfo['name']) ?></strong> (<?= h($setupLinkInfo['email']) ?>) is ready to log in.</p>
    <?php if ($setupLinkInfo['emailed']): ?>
        <p>A set-password link was emailed to them. You can also copy it below if needed.</p>
    <?php else: ?>
        <p><strong>The email could not be sent from this server.</strong> Copy this link and send it to them manually:</p>
    <?php endif; ?>
    <p><input type="text" readonly value="<?= h($setupLinkInfo['url']) ?>" onclick="this.select()" class="setup-link-box"></p>
    <p>This link expires in 72 hours.</p>
</div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert--error"><?= h($error) ?></div>
<?php endforeach; ?>

<form method="post" class="stacked-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($client): ?><input type="hidden" name="id" value="<?= (int) $client['id'] ?>"><?php endif; ?>
    <label>Full name
        <input type="text" name="name" required value="<?= h($client['name'] ?? $_POST['name'] ?? '') ?>">
    </label>
    <label>Email
        <input type="email" name="email" required value="<?= h($client['email'] ?? $_POST['email'] ?? '') ?>">
    </label>
    <label>Phone (optional)
        <input type="text" name="phone" value="<?= h($client['phone'] ?? $_POST['phone'] ?? '') ?>">
    </label>
    <?php if ($client): ?>
    <label>Status
        <select name="status">
            <option value="active" <?= ($client['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="disabled" <?= ($client['status'] ?? '') === 'disabled' ? 'selected' : '' ?>>Disabled</option>
        </select>
    </label>
    <?php endif; ?>
    <div class="booking-card__actions">
        <button type="submit" class="btn btn--primary"><?= $client ? 'Save changes' : 'Create client' ?></button>
        <a href="<?= url('/admin/clients.php') ?>" class="btn btn--ghost">Cancel</a>
    </div>
</form>

<?php if ($client && isset($client['id'])): ?>
<form method="post" class="inline-form">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="resend_link">
    <input type="hidden" name="id" value="<?= (int) $client['id'] ?>">
    <button type="submit" class="btn btn--ghost">Send a new set-password link</button>
</form>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/partials/footer.php'; ?>
