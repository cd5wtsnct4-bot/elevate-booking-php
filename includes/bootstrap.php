<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

session_name(SESSION_NAME);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'secure' => FORCE_SECURE_COOKIES,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/auth.php';
