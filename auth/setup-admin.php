<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/app.php';
require_once APP_ROOT.'/includes/admin.php';

if(scalar("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='ADMIN' AND u.is_active=1 LIMIT 1")){
    http_response_code(404);
    exit('Not found.');
}

$setupToken=getenv('CELCO_ADMIN_SETUP_TOKEN');
if($setupToken===false || $setupToken==='')$setupToken=$_SERVER['CELCO_ADMIN_SETUP_TOKEN']??'';
$enabled=is_string($setupToken) && preg_match('/^[a-f0-9]{64}$/D',$setupToken)===1;
if(!$enabled)http_response_code(503);
$error='';

if($_SERVER['REQUEST_METHOD']==='POST' && $enabled){
    verify_csrf();
    try {
        $provided=$_POST['setup_token']??'';
        ensure(is_string($provided) && hash_equals($setupToken,$provided),'The setup token is invalid.');
        $username=string_value($_POST['username']??'',80);
        $password=password_value($_POST['password']??null);
        $confirmation=$_POST['confirm_password']??null;
        ensure(is_string($confirmation) && hash_equals($password,$confirmation),'Passwords do not match.');
        create_initial_admin($username,$password);
        clear_session();
        $_SESSION['admin_setup_complete']=true;
        redirect_to('auth/login.php');
    } catch(Throwable $exception) {
        $error=friendly_error($exception);
    }
}
?><!doctype html>
<html lang="en"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Administrator setup | CELCO IMS</title>
    <link rel="icon" type="image/png" href="<?= e(app_url('assets/image/CELCO.png')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/login.css')) ?>">
</head><body class="login-page"><main class="login-shell">
<section class="login-identity">
    <div class="wordmark"><img src="<?= e(app_url('assets/image/CELCO.png')) ?>" alt=""><b>CELCO</b></div>
    <div><p>Initial account setup</p><h1>Create the first administrator</h1><div class="power-lines" aria-hidden="true"></div></div>
    <span>This registration closes after an active administrator is created.</span>
</section>
<section class="login-form"><p class="eyebrow">CELCO IMS</p><h2>Admin setup</h2>
    <?php if($error): ?><p role="alert"><?= e($error) ?></p><?php endif; ?>
    <?php if(!$enabled): ?>
        <p role="status">Setup is disabled. Configure CELCO_ADMIN_SETUP_TOKEN as a 64-character lowercase hexadecimal secret in the web server environment, then restart Apache.</p>
        <a href="<?= e(app_url('auth/login.php')) ?>">Return to sign in</a>
    <?php else: ?>
        <form method="post" autocomplete="off"><?= csrf_field() ?>
            <label>Setup token<input name="setup_token" type="password" minlength="64" maxlength="64" autocomplete="off" required></label>
            <label>Username<input name="username" maxlength="80" autocomplete="username" required></label>
            <label>Password<input name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
            <label>Confirm password<input name="confirm_password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
            <button type="submit">Create administrator</button>
        </form>
        <small>Use the private setup token provided by the server administrator.</small>
    <?php endif; ?>
</section></main></body></html>
