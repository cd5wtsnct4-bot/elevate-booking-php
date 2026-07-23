<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../lib/MicrosoftGraph.php';

$state = $_GET['state'] ?? '';
$pending = $_SESSION['pending_ms_login'] ?? null;

if (!$pending || !hash_equals($pending['state'], (string) $state)) {
    flash('error', 'That Microsoft sign-in link expired or is invalid. Please try again.');
    redirect('/index.php');
}
unset($_SESSION['pending_ms_login']);

if (isset($_GET['error'])) {
    flash('error', 'Microsoft sign-in was cancelled or failed: ' . h($_GET['error_description'] ?? $_GET['error']));
    redirect('/index.php');
}

try {
    $tokens = MicrosoftGraph::exchangeCodeForToken((string) $_GET['code'], MicrosoftGraph::loginRedirectUri());
    $me = MicrosoftGraph::getMe($tokens['access_token']);
    $email = $me['mail'] ?? $me['userPrincipalName'] ?? null;

    if (!$email) {
        throw new RuntimeException('Could not determine your email address from Microsoft.');
    }

    // Authentication is Microsoft's job; authorization is still ours — only
    // an email that already has an active admin account in our own `users`
    // table may sign in this way. This intentionally does NOT auto-create
    // admin accounts for arbitrary tenant users.
    $stmt = db()->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin'");
    $stmt->execute([strtolower($email)]);
    $adminRow = $stmt->fetch();

    if (!$adminRow) {
        flash('error', "No admin account matches {$email}. Ask an existing admin to create one, or log in with a password instead.");
        redirect('/index.php');
    }
    if ($adminRow['status'] !== 'active') {
        flash('error', 'This admin account has been disabled.');
        redirect('/index.php');
    }

    // Combined step: signing in also connects this account's own calendar,
    // same as the earlier prototype's behavior. The info@ shared mailbox
    // still needs its own separate "Connect another" step on Calendar Sync,
    // since you can't sign in directly as a shared mailbox.
    $expiresAt = date('Y-m-d H:i:s', time() + (int) ($tokens['expires_in'] ?? 3600));
    db()->prepare(
        'INSERT INTO calendar_accounts (label, mailbox_email, is_shared_mailbox, access_token_enc, refresh_token_enc, token_expires_at, connected_by, last_sync_error)
         VALUES (?, ?, 0, ?, ?, ?, ?, NULL)
         ON DUPLICATE KEY UPDATE
            access_token_enc = VALUES(access_token_enc), refresh_token_enc = VALUES(refresh_token_enc),
            token_expires_at = VALUES(token_expires_at), connected_by = VALUES(connected_by), last_sync_error = NULL'
    )->execute([
        'Admin',
        strtolower($email),
        encryptSecret($tokens['access_token']),
        encryptSecret($tokens['refresh_token']),
        $expiresAt,
        $adminRow['id'],
    ]);

    loginUser($adminRow);
    flash('success', 'Signed in with Microsoft — your calendar is connected.');
    redirect('/admin/dashboard.php');
} catch (Throwable $e) {
    flash('error', 'Microsoft sign-in failed: ' . $e->getMessage());
    redirect('/index.php');
}
