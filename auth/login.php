<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/app.php';
if(current_user()) redirect_to('');
$error='';
$adminSetupComplete=!empty($_SESSION['admin_setup_complete']);
unset($_SESSION['admin_setup_complete']);
if($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try { if(attempt_login(string_value($_POST['username']??'',80),is_string($_POST['password']??null) ? substr($_POST['password'],0,1024) : '')) redirect_to(''); $error='The username or password is incorrect.'; }
    catch(Throwable $exception){$error=friendly_error($exception);}
}
?><!doctype html>
<html lang="en"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign in | CELCO IMS</title>
    <link rel="icon" type="image/png" href="<?= e(app_url('assets/image/CELCO.png')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/login.css')) ?>">
</head><body class="login-page"><main class="login-shell">
<section class="login-identity">
    <div class="wordmark"><img src="<?= e(app_url('api/logo.php')) ?>" alt=""><b>CELCO</b></div>
    <div><p><?= e(setting('company_name','Camotes Electric Cooperative, Inc.')) ?></p>
    <h1><?= e(setting('system_name','Material inventory management')) ?></h1><div class="power-lines" aria-hidden="true"></div></div>
    <span>Camotes Islands, Cebu</span>
</section>
<section class="login-form"><p class="eyebrow">CELCO IMS</p><h2>Sign in</h2><p>Access your inventory workspace.</p>
    <?php if($adminSetupComplete): ?><p role="status">Administrator created. You can now sign in.</p><?php endif; ?>
    <?php if($error): ?><p role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post"><?= csrf_field() ?>
        <label>Username<input name="username" maxlength="80" autocomplete="username" required autofocus></label>
        <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
        <button>Sign in</button>
    </form><small>For account access, contact your system administrator.</small>
</section></main></body></html>
