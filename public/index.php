<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (currentUser()) {
    redirect(currentUser()['role'] === 'admin' ? '/admin/dashboard.php' : '/client/dashboard.php');
}

$firstRun = !anyAdminExists();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    if ($firstRun) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if (!isValidEmail($email)) {
            $errors[] = 'A valid email is required.';
        }
        if (strlen($password) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'INSERT INTO users (name, email, password_hash, role, must_change_password) VALUES (?, ?, ?, \'admin\', 0)'
            );
            $stmt->execute([$name, strtolower($email), password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) db()->lastInsertId();

            loginUser(['id' => $userId, 'name' => $name, 'email' => strtolower($email), 'role' => 'admin']);
            redirect('/admin/dashboard.php');
        }
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if (!isValidEmail($email) || $password === '') {
            $errors[] = 'Enter your email and password.';
        } elseif (isLockedOut($email)) {
            $errors[] = 'Too many failed attempts. Please try again in a few minutes.';
        } else {
            $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
            $stmt->execute([strtolower($email)]);
            $row = $stmt->fetch();

            if (!$row || !$row['password_hash'] || !password_verify($password, $row['password_hash'])) {
                recordLoginAttempt($email, false);
                $errors[] = 'Incorrect email or password.';
            } elseif ($row['status'] !== 'active') {
                recordLoginAttempt($email, false);
                $errors[] = 'This account has been disabled. Contact Elevate SJC.';
            } else {
                recordLoginAttempt($email, true);
                loginUser($row);
                redirect($row['role'] === 'admin' ? '/admin/dashboard.php' : '/client/dashboard.php');
            }
        }
    }
}

$pageTitle = $firstRun ? 'Create admin account' : 'Log in';
$bodyClass = 'auth-page';
require __DIR__ . '/../includes/partials/header.php';
?>
<div class="auth-card">
    <h1><?= $firstRun ? 'Create your admin account' : 'Log in' ?></h1>
    <p class="auth-card__subtitle">
        <?= $firstRun
            ? 'No admin account exists yet. Create one now to finish setup.'
            : 'Access is by invitation only — contact Elevate SJC if you need an account.' ?>
    </p>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert--error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <form method="post" class="stacked-form">
        <?= csrfField() ?>
        <?php if ($firstRun): ?>
        <label>Name
            <input type="text" name="name" required value="<?= h($_POST['name'] ?? '') ?>">
        </label>
        <?php endif; ?>
        <label>Email
            <input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>" autofocus>
        </label>
        <label>Password
            <input type="password" name="password" required minlength="<?= $firstRun ? 10 : 1 ?>">
        </label>
        <button type="submit" class="btn btn--primary"><?= $firstRun ? 'Create account' : 'Log in' ?></button>
    </form>

    <?php if (!$firstRun && MS_CLIENT_ID !== '' && MS_CLIENT_SECRET !== ''): ?>
    <div class="auth-divider"><span>or</span></div>
    <a href="/auth/microsoft-login.php" class="btn btn--ghost btn--block">Sign in with Microsoft</a>
    <p class="auth-card__hint">For admins only — signs you in with your Elevate SJC Microsoft 365 account and connects your calendar.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/partials/footer.php'; ?>
