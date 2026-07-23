<?php

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * The path component of APP_BASE_URL, e.g. '' when the app sits at the
 * domain root, or '/bookings' when it's deployed into a subfolder
 * (https://seanp.co.za/bookings). Every internal link, redirect, and asset
 * reference should go through this (via url()) instead of a bare
 * root-relative path, so moving the app between a subfolder and the domain
 * root is just a one-line APP_BASE_URL change.
 */
function basePath(): string
{
    static $path = null;
    if ($path === null) {
        $path = rtrim((string) parse_url(APP_BASE_URL, PHP_URL_PATH), '/');
    }
    return $path;
}

/** Prefixes a root-relative app path (e.g. '/admin/dashboard.php') with basePath(). */
function url(string $path): string
{
    return basePath() . $path;
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $value;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

function csrfVerify(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function requireCsrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrfVerify()) {
        http_response_code(403);
        exit('Invalid or expired form submission. Please go back and try again.');
    }
}

function csrfVerifyToken(string $token): bool
{
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/**
 * Reads a JSON request body as an associative array (used by the AJAX
 * endpoints under public/api/, which send application/json rather than
 * regular form-encoded POST bodies).
 */
function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function randomToken(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function isValidEmail(?string $email): bool
{
    return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function formatDateHuman(string $isoDate): string
{
    $ts = strtotime($isoDate);
    return $ts !== false ? date('l, j F Y', $ts) : $isoDate;
}

/**
 * Encrypt a string for storage (OAuth tokens) using AES-256-GCM with the
 * app-wide key. Returns a single base64 blob containing iv+tag+ciphertext.
 */
function encryptSecret(string $plaintext): string
{
    $key = hex2bin(APP_ENCRYPTION_KEY);
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ciphertext);
}

function decryptSecret(?string $blob): ?string
{
    if (!$blob) {
        return null;
    }
    $key = hex2bin(APP_ENCRYPTION_KEY);
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 28) {
        return null;
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plaintext === false ? null : $plaintext;
}
