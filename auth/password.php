<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/app.php';
$user=require_user();
post_action(function()use($user){$changed=atomic(function()use($user){
    $failures=(int)scalar("SELECT COUNT(*) FROM audit_logs WHERE user_id=? AND action='PASSWORD_FAILED' AND created_at>=NOW()-INTERVAL 15 MINUTE",[$user['id']]);
    ensure($failures<8,'Too many password attempts. Try again in 15 minutes.');
    $hash=scalar('SELECT password_hash FROM users WHERE id=? FOR UPDATE',[$user['id']]);
    if(!password_verify((string)($_POST['current']??''),$hash))return false;
    $new=password_value($_POST['new_password']??'');ensure($new===($_POST['confirm']??null),'Passwords do not match.');
    $hash=password_hash($new,PASSWORD_DEFAULT);run_sql('UPDATE users SET password_hash=? WHERE id=?',[$hash,$user['id']]);audit('PASSWORD_CHANGE','users',(int)$user['id']);
    $_SESSION['credential_version']=hash('sha256',$hash);session_regenerate_id(true);return true;
});if(!$changed){audit('PASSWORD_FAILED','users',(int)$user['id']);throw new InputError('Current password is incorrect.');}},'auth/password.php');
layout_start('Change password');echo '<form method="post" class="panel">'.csrf_field();foreach(['current'=>'Current password','new_password'=>'New password','confirm'=>'Confirm password'] as $k=>$v)field($k,$v,'','password',[],true);button_action('change','Change password');echo '</form>';layout_end();
