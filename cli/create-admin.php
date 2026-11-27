<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config/app.php';
echo "Administrator username: ";$name=string_value(fgets(STDIN),80);
function prompt_password(): string {
    if(PHP_OS_FAMILY==='Windows' && stream_isatty(STDIN)){
        $command='powershell -NoProfile -Command "$s=Read-Host -AsSecureString; [Console]::Write([Net.NetworkCredential]::new(\'\',$s).Password)"';
        return (string)shell_exec($command);
    }
    $hide=PHP_OS_FAMILY!=='Windows' && stream_isatty(STDIN);
    if($hide) system('stty -echo');
    try{return rtrim((string)fgets(STDIN),"\r\n");}finally{if($hide)system('stty echo');}
}
echo "Password (12–72 bytes): ";$password=password_value(prompt_password());
echo "\nConfirm password: ";ensure(hash_equals($password,prompt_password()),'Passwords do not match.');
atomic(function()use($name,$password){
    $role=id_value(scalar("SELECT id FROM roles WHERE code='ADMIN' FOR UPDATE"));
    ensure(!scalar('SELECT id FROM users WHERE role_id=? AND is_active=1 LIMIT 1',[$role]),'An active administrator already exists.');
    run_sql('INSERT INTO users(role_id,username,password_hash) VALUES(?,?,?)',[$role,$name,password_hash($password,PASSWORD_DEFAULT)]);
    $id=inserted_id();audit('CLI_ADMIN_SETUP','users',$id,[],$id);
}); echo "\nAdministrator created.\n";
