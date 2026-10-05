<?php
/* ================================================================
   OKOYA FOOD COMPANY LIMITED — DATABASE CONFIGURATION
   InfinityFree: if0_42572843
   ================================================================ */

define('DB_HOST', 'sql300.infinityfree.com');  // ← CHECK YOUR CPANEL FOR EXACT HOST
define('DB_NAME', 'if0_42572843_okoya_food_db');
define('DB_USER', 'if0_42572843');
define('DB_PASS', 'YOUR_INFINITYFREE_MYSQL_PASSWORD');  // ← REPLACE WITH REAL PASSWORD

define('APP_NAME', 'OKOYA GROUP');
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCK_MINUTES', 15);
define('REMEMBER_DAYS', 30);

/* ---------- Secure session ---------- */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('OKOYA_SESS');
    session_start();
}

/* ---------- PDO connection ---------- */
try {
    $pdo = new PDO(
    'mysql:host=sql102.infinityfree.com;dbname=if0_42572843_okoya_food_db;charset=utf8mb4',
    'if0_42572843',
    'NsOySygIU7Rzjg',
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);
} catch (PDOException $ex) {
    die('<div style="max-width:560px;margin:60px auto;padding:26px 30px;font-family:monospace;
         background:#fdecea;color:#8b1e12;border:1px solid #f5b7b1;border-radius:12px">
         <strong>⚠ Database connection failed</strong><br><br>'
         . htmlspecialchars($ex->getMessage(), ENT_QUOTES) .
         '<br><br>Check DB_HOST / DB_NAME / DB_USER / DB_PASS in <b>config.php</b>.</div>');
}