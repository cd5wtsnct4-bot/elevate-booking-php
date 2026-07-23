<?php
/**
 * Copy this file to config.php (same directory) and fill in real values.
 * Never commit config.php — it holds database credentials and app secrets.
 */

// --- Database ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// --- App ---
// Public URL of the site, no trailing slash. Used to build the Microsoft
// OAuth redirect URI and links in emails.
define('APP_BASE_URL', 'https://seanp.co.za');

// 32-byte key (64 hex chars) used to encrypt OAuth tokens at rest.
// Generate one with: php -r "echo bin2hex(random_bytes(32));"
define('APP_ENCRYPTION_KEY', '');

// Name shown in the browser tab, emails, etc.
define('APP_NAME', 'Elevate SJC Booking');

// --- Mail (used for admin-provisioned client set-password links) ---
// Most cPanel hosts have a working local mail() transport out of the box.
// Set a From address that matches your domain to avoid spam filtering.
define('MAIL_FROM_ADDRESS', 'no-reply@seanp.co.za');
define('MAIL_FROM_NAME', 'Elevate SJC');

// --- Microsoft Graph / Azure App Registration ---
// portal.azure.com > Microsoft Entra ID > App registrations > New registration
// Redirect URI (Web): {APP_BASE_URL}/admin/calendar-settings.php?action=callback
// Delegated permissions: Calendars.ReadWrite, offline_access, User.Read
define('MS_CLIENT_ID', '');
define('MS_CLIENT_SECRET', '');
define('MS_TENANT_ID', 'common');

// --- Session ---
define('SESSION_NAME', 'elevate_booking_sess');

// --- Environment ---
// Set to false only while testing over plain http:// on localhost.
define('FORCE_SECURE_COOKIES', true);
