<?php
declare(strict_types=1);
function clear_session(): void {
    $_SESSION=[]; if(session_status()===PHP_SESSION_ACTIVE) session_regenerate_id(true);
}
function attempt_login(string $name,string $password): bool {
    $ip=$_SERVER['REMOTE_ADDR'] ?? 'local';
    // Serialize attempts per address and account across concurrent PHP workers.
    $locks=['ip:'.$ip,'user:'.mb_strtolower($name)];
    $held=[];
    try {
        foreach($locks as $key) {
            $lock='ims:'.substr(hash('sha256',$key),0,52);
            ensure((bool)scalar('SELECT GET_LOCK(?,3)',[$lock]),'Try again shortly.');
            $held[]=$lock;
        }
        $count=scalar("SELECT COUNT(*) FROM audit_logs WHERE action='LOGIN_FAILED' AND created_at>=NOW()-INTERVAL 15 MINUTE AND (ip_address=? OR JSON_UNQUOTE(JSON_EXTRACT(details,'$.account'))=?)",[$ip,hash('sha256',mb_strtolower($name))]);
        ensure((int)$count<8,'Too many attempts. Try again in 15 minutes.');
        $user=row('SELECT id,password_hash,is_active FROM users WHERE username=?',[$name]);
        $valid=password_verify($password,$user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if(!$user || !$valid || !$user['is_active']) { audit('LOGIN_FAILED','users',null,['account'=>hash('sha256',mb_strtolower($name))]); return false; }
        atomic(function() use($user) {
            run_sql('UPDATE users SET last_login_at=NOW() WHERE id=?',[$user['id']]);
            audit('LOGIN','users',(int)$user['id'],[],(int)$user['id']);
        });
        clear_session();
        $_SESSION['user_id']=(int)$user['id']; $_SESSION['credential_version']=hash('sha256',$user['password_hash']); $_SESSION['last_seen']=time();
        return true;
    } finally {foreach(array_reverse($held) as $lock) scalar('SELECT RELEASE_LOCK(?)',[$lock]);}
}

