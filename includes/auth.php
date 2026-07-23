<?php
declare(strict_types=1);

const LOGIN_MAX_ATTEMPTS = 8;
const LOGIN_LOCKOUT_WINDOW_MINUTES = 15;
const PASSWORD_SETUP_TOKEN_TTL_HOURS = 72;

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function requireLogin(): array
{
    $user = currentUser();
    if (!$user) {
        redirect('/index.php');
    }
    return $user;
}

function requireAdmin(): array
{
    $user = requireLogin();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Admins only.');
    }
    return $user;
}

function loginUser(array $userRow): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $userRow['id'],
        'name' => $userRow['name'],
        'email' => $userRow['email'],
        'role' => $userRow['role'],
    ];
}

function logoutUser(): void
{
    $_SESSION = [];
    session_destroy();
}

function clientIp(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function recordLoginAttempt(string $email, bool $succeeded): void
{
    $stmt = db()->prepare(
        'INSERT INTO login_attempts (email, ip, succeeded) VALUES (?, ?, ?)'
    );
    $stmt->execute([strtolower($email), clientIp(), $succeeded ? 1 : 0]);
}

function isLockedOut(string $email): bool
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE email = ? AND succeeded = 0
           AND created_at > (NOW() - INTERVAL ? MINUTE)"
    );
    $stmt->execute([strtolower($email), LOGIN_LOCKOUT_WINDOW_MINUTES]);
    return (int) $stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
}

/**
 * Creates a one-time password-setup link for an admin-provisioned account.
 * Returns the plaintext token (only ever available at creation time — only
 * its SHA-256 hash is stored).
 */
function createPasswordSetupToken(int $userId): string
{
    $token = randomToken(32);
    $hash = hash('sha256', $token);
    $expiresAt = (new DateTime())->modify('+' . PASSWORD_SETUP_TOKEN_TTL_HOURS . ' hours')->format('Y-m-d H:i:s');

    db()->prepare(
        'INSERT INTO password_setup_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
    )->execute([$userId, $hash, $expiresAt]);

    return $token;
}

/**
 * Looks up a still-valid, unused password-setup token. Does not consume it —
 * call markPasswordSetupTokenUsed() only after the password has actually
 * been set.
 */
function findPasswordSetupToken(string $token): ?array
{
    $hash = hash('sha256', $token);
    $stmt = db()->prepare(
        'SELECT pst.*, u.name, u.email FROM password_setup_tokens pst
         JOIN users u ON u.id = pst.user_id
         WHERE pst.token_hash = ? AND pst.used_at IS NULL AND pst.expires_at > NOW()'
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function markPasswordSetupTokenUsed(int $tokenId): void
{
    db()->prepare('UPDATE password_setup_tokens SET used_at = NOW() WHERE id = ?')->execute([$tokenId]);
}

function anyAdminExists(): bool
{
    $stmt = db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    return (int) $stmt->fetchColumn() > 0;
}
