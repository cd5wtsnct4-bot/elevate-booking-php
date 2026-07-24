<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$admin = requireAdmin();

$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$admin['id']]);
$me = $stmt->fetch();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!$me['password_hash'] || !password_verify($currentPassword, $me['password_hash'])) {
        $errors[] = 'Current password is incorrect.';
    }
    if ($name === '') {
        $errors[] = 'Name is required.';
    }
    if (!isValidEmail($email)) {
        $errors[] = 'A valid email is required.';
    }
    if ($newPassword !== '' && strlen($newPassword) < 10) {
        $errors[] = 'New password must be at least 10 characters.';
    }
    if ($newPassword !== '' && $newPassword !== $confirmPassword) {
        $errors[] = 'New passwords do not match.';
    }

    if (!$errors) {
        $dupStmt = db()->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $dupStmt->execute([strtolower($email), $me['id']]);
        if ($dupStmt->fetch()) {
            $errors[] = 'Another account already uses that email.';
        }
    }

    if (!$errors) {
        if ($newPassword !== '') {
            db()->prepare('UPDATE users SET name = ?, email = ?, password_hash = ? WHERE id = ?')
                ->execute([$name, strtolower($email), password_hash($newPassword, PASSWORD_DEFAULT), $me['id']]);
        } else {
            db()->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')
                ->execute([$name, strtolower($email), $me['id']]);
        }

        // Keep the session in sync with the new values immediately.
        $_SESSION['user']['name'] = $name;
        $_SESSION['user']['email'] = strtolower($email);

        flash('success', 'Account updated.' . ($newPassword !== '' ? ' Password changed.' : ''));
        redirect(url('/admin/account.php'));
    }

    // Re-fetch so the form reflects the DB state (not the failed attempt) on error.
    $me['name'] = $name !== '' ? $name : $me['name'];
    $me['email'] = $email !== '' ? $email : $me['email'];
}

$pageTitle = 'My Account';
require __DIR__ . '/../../includes/partials/header.php';
?>
<div class="page-intro"><h1>My Account</h1><p>Update your own name, email, or password.</p></div>

<?php foreach ($errors as $error): ?>
    <div class="alert alert--error"><?= h($error) ?></div>
<?php endforeach; ?>

<form method="post" class="stacked-form">
    <?= csrfField() ?>
    <label>Name
        <input type="text" name="name" required value="<?= h($me['name']) ?>">
    </label>
    <label>Email
        <input type="email" name="email" required value="<?= h($me['email']) ?>">
    </label>

    <hr style="border:none;border-top:1px solid var(--border);margin:6px 0;">

    <label>New password <span class="muted">(leave blank to keep your current password)</span>
        <input type="password" name="new_password" minlength="10" autocomplete="new-password">
    </label>
    <label>Confirm new password
        <input type="password" name="confirm_password" minlength="10" autocomplete="new-password">
    </label>

    <hr style="border:none;border-top:1px solid var(--border);margin:6px 0;">

    <label>Current password <span class="muted">(required to save any change above)</span>
        <input type="password" name="current_password" required autocomplete="current-password">
    </label>

    <div class="booking-card__actions">
        <button type="submit" class="btn btn--primary">Save changes</button>
    </div>
</form>

<?php if (MS_CLIENT_ID !== '' && MS_CLIENT_SECRET !== ''): ?>
<div class="alert alert--warning" style="margin-top:24px;">
    <strong>Using "Sign in with Microsoft"?</strong> It only works if your Microsoft 365 account's
    email exactly matches the email above. If you change your email here, make sure the matching
    Microsoft 365 account exists too — and if you'll be managing the <code>info@seanp.co.za</code>
    shared calendar, that Microsoft account needs the same Full Access + Calendar folder Editor
    permission on it that's documented in DEPLOY.md.
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/partials/footer.php'; ?>
