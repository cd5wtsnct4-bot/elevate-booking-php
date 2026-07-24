<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../lib/MicrosoftGraph.php';

$admin = requireAdmin();

$msConfigured = MS_CLIENT_ID !== '' && MS_CLIENT_SECRET !== '';

// --- OAuth callback from Microsoft ---
if (($_GET['action'] ?? '') === 'callback') {
    $state = $_GET['state'] ?? '';
    $pending = $_SESSION['pending_calendar_connect'] ?? null;

    if (!$pending || !hash_equals($pending['state'], (string) $state)) {
        flash('error', 'Calendar connection expired or invalid. Please try again.');
        redirect(url('/admin/calendar-settings.php'));
    }
    unset($_SESSION['pending_calendar_connect']);

    if (isset($_GET['error'])) {
        flash('error', 'Microsoft sign-in was cancelled or failed: ' . h($_GET['error_description'] ?? $_GET['error']));
        redirect(url('/admin/calendar-settings.php'));
    }

    try {
        $tokens = MicrosoftGraph::exchangeCodeForToken((string) $_GET['code'], MicrosoftGraph::calendarConnectRedirectUri());
        $mailboxEmail = $pending['shared_mailbox_email'] ?: null;

        if (!$mailboxEmail) {
            $me = MicrosoftGraph::getMe($tokens['access_token']);
            $mailboxEmail = $me['mail'] ?? $me['userPrincipalName'] ?? null;
        }
        if (!$mailboxEmail) {
            throw new RuntimeException('Could not determine a mailbox address for this account.');
        }

        $expiresAt = date('Y-m-d H:i:s', time() + (int) ($tokens['expires_in'] ?? 3600));

        // Label/is_shared_mailbox are intentionally omitted from the UPDATE
        // clause — reconnecting an already-connected mailbox (e.g. via the
        // combined Microsoft login below) refreshes tokens without
        // clobbering a label the admin already customized.
        db()->prepare(
            'INSERT INTO calendar_accounts (label, mailbox_email, is_shared_mailbox, access_token_enc, refresh_token_enc, token_expires_at, connected_by, last_sync_error)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE
                access_token_enc = VALUES(access_token_enc), refresh_token_enc = VALUES(refresh_token_enc),
                token_expires_at = VALUES(token_expires_at), connected_by = VALUES(connected_by), last_sync_error = NULL'
        )->execute([
            $pending['label'],
            strtolower($mailboxEmail),
            $pending['shared_mailbox_email'] ? 1 : 0,
            encryptSecret($tokens['access_token']),
            encryptSecret($tokens['refresh_token']),
            $expiresAt,
            $admin['id'],
        ]);

        flash('success', 'Connected ' . $mailboxEmail . '.');
    } catch (Throwable $e) {
        flash('error', 'Could not connect that calendar: ' . $e->getMessage());
    }

    redirect(url('/admin/calendar-settings.php'));
}

// --- Start a new connection ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'connect') {
    requireCsrf();
    $label = trim($_POST['label'] ?? '');
    $sharedMailbox = trim($_POST['shared_mailbox_email'] ?? '');

    if ($label === '') {
        flash('error', 'Give this calendar a label, e.g. "Admin" or "Info (shared)".');
        redirect(url('/admin/calendar-settings.php'));
    }
    if ($sharedMailbox !== '' && !isValidEmail($sharedMailbox)) {
        flash('error', 'The shared mailbox address is not a valid email.');
        redirect(url('/admin/calendar-settings.php'));
    }

    $state = randomToken(16);
    $_SESSION['pending_calendar_connect'] = [
        'label' => $label,
        'shared_mailbox_email' => $sharedMailbox ?: null,
        'state' => $state,
    ];
    redirect(MicrosoftGraph::getAuthorizationUrl($state, MicrosoftGraph::calendarConnectRedirectUri()));
}

// --- Disconnect ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disconnect') {
    requireCsrf();
    db()->prepare('DELETE FROM calendar_accounts WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
    flash('success', 'Calendar disconnected.');
    redirect(url('/admin/calendar-settings.php'));
}

$calendars = db()->query('SELECT * FROM calendar_accounts ORDER BY label')->fetchAll();

$pageTitle = 'Calendar sync';
require __DIR__ . '/../../includes/partials/header.php';
?>
<div class="page-intro"><h1>Calendar sync</h1><p>Connect <code>claudette@seanp.co.za</code> and the <code>info@seanp.co.za</code> shared mailbox so client availability reflects both calendars.</p></div>

<?php if (!$msConfigured): ?>
<div class="alert alert--warning">
    <strong>Microsoft app credentials aren't set yet.</strong>
    Add <code>MS_CLIENT_ID</code> and <code>MS_CLIENT_SECRET</code> to <code>includes/config.php</code> after creating an
    Azure App Registration (see DEPLOY.md) before you can connect a calendar.
</div>
<?php endif; ?>

<section class="admin-section">
    <h2>Connected calendars</h2>
    <?php if (!$calendars): ?>
        <p class="muted">No calendars connected yet.</p>
    <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
        <thead><tr><th>Label</th><th>Mailbox</th><th>Type</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($calendars as $cal): ?>
            <tr>
                <td><?= h($cal['label']) ?></td>
                <td><?= h($cal['mailbox_email']) ?></td>
                <td><?= $cal['is_shared_mailbox'] ? 'Shared mailbox' : 'Own mailbox' ?></td>
                <td>
                    <?php if ($cal['last_sync_error']): ?>
                        <span class="status-pill status-pill--declined">Sync error</span>
                        <div class="muted"><?= h($cal['last_sync_error']) ?></div>
                    <?php else: ?>
                        <span class="status-pill status-pill--approved">OK</span>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" class="inline-form" onsubmit="return confirm('Disconnect this calendar? Existing bookings keep their history but will stop syncing.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="disconnect">
                        <input type="hidden" name="id" value="<?= (int) $cal['id'] ?>">
                        <button type="submit" class="link-btn link-btn--danger">Disconnect</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<?php if ($msConfigured): ?>
<section class="admin-section">
    <h2>Connect another calendar</h2>
    <form method="post" class="stacked-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="connect">
        <label>Label
            <input type="text" name="label" placeholder="e.g. Admin or Info (shared)" required>
        </label>
        <label>Shared mailbox address (leave blank to connect your own signed-in calendar)
            <input type="email" name="shared_mailbox_email" placeholder="info@seanp.co.za">
        </label>
        <p class="muted">
            To connect <code>info@seanp.co.za</code> as a shared mailbox, sign in with the
            <code>claudette@seanp.co.za</code> Microsoft account (which needs Full Access delegate permission
            on that shared mailbox — see DEPLOY.md) and enter <code>info@seanp.co.za</code> above.
        </p>
        <p class="muted">
            <strong>If Microsoft Graph can't find that mailbox after connecting</strong> (a sync error
            mentioning it couldn't resolve the account), the mailbox's underlying Microsoft 365 sign-in
            name may differ from its email address — check <strong>Recipients &rarr; Mailboxes</strong> in
            the Exchange admin center for its actual <em>UserPrincipalName</em>, and enter that here
            instead of the email address.
        </p>
        <button type="submit" class="btn btn--primary">Connect with Microsoft</button>
    </form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/partials/footer.php'; ?>
