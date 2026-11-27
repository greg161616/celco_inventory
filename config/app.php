<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/application.log');
date_default_timezone_set('Asia/Manila');
require_once APP_ROOT . '/includes/functions.php';
set_exception_handler('app_exception');
require_once APP_ROOT . '/config/db.php';
$pdo->exec("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@sql_mode,''), 'STRICT_ALL_TABLES')");
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
date_default_timezone_set(setting('timezone', 'Asia/Manila'));
run_sql('SET time_zone = ?', [date('P')]);
require_once APP_ROOT . '/includes/validation.php';
require_once APP_ROOT . '/includes/csrf.php';
require_once APP_ROOT . '/includes/audit.php';
require_once APP_ROOT . '/includes/permissions.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/ui.php';
require_once APP_ROOT . '/layouts/header.php';
if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('CELCOIMS');
    session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off','samesite'=>'Lax','path'=>rtrim(app_url(''), '/') . '/']);
    session_start();
}

