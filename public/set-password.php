<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$tokenRow = is_string($token) && $token !== '' ? findPasswordSetupToken($token) : null;

if (!$tokenRow) {
    $pageTitle = 'Link expired';
    require __DIR__ . '/../includes/partials/header.php';
    ?>
    <div class="auth-card">
        <h1>This link is invalid or has expired</h1>
        <p>Ask Elevate SJC to send you a new one.</p>
        <p><a href="/index.php">Back to log in</a></p>
    </div>
    <?php
    require __DIR__ . '/../includes/partials/footer.php';
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (strlen($password) < 10) {
        $errors[] = 'Password must be at least 10 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $tokenRow['user_id']]);
        markPasswordSetupTokenUsed((int) $tokenRow['id']);

        loginUser(['id' => $tokenRow['user_id'], 'name' => $tokenRow['name'], 'email' => $tokenRow['email'], 'role' => 'client']);
        flash('success', 'Your password is set. Welcome!');
        redirect('/client/dashboard.php');
    }
}

$pageTitle = 'Set your password';
require __DIR__ . '/../includes/partials/header.php';
?>
<div class="auth-card">
    <h1>Welcome, <?= h($tokenRow['name']) ?></h1>
    <p class="auth-card__subtitle">Set a password for <?= h($tokenRow['email']) ?> to finish setting up your account.</p>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert--error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <form method="post" class="stacked-form">
        <?= csrfField() ?>
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <label>New password
            <input type="password" name="password" required minlength="10" autofocus>
        </label>
        <label>Confirm password
            <input type="password" name="confirm_password" required minlength="10">
        </label>
        <button type="submit" class="btn btn--primary">Set password &amp; log in</button>
    </form>
</div>
<?php require __DIR__ . '/../includes/partials/footer.php'; ?>
