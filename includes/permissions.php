<?php
declare(strict_types=1);
function current_user(): ?array {
    static $cached=null;
    if(empty($_SESSION['user_id'])) return null;
    if($cached!==null && (int)$cached['id']===(int)$_SESSION['user_id']) return $cached;
    $user=row('SELECT u.id,u.username,u.email,u.employee_id,u.role_id,u.password_hash,r.code role_code,r.name role_name,e.department_id,e.full_name FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN employees e ON e.id=u.employee_id WHERE u.id=? AND u.is_active=1',[$_SESSION['user_id']]);
    if(!$user || !hash_equals($_SESSION['credential_version'] ?? '',hash('sha256',$user['password_hash']))) {unset($_SESSION['user_id']); return null;}
    unset($user['password_hash']); return $cached=$user;
}
function can(string $permission): bool {
    $user=current_user(); if(!$user) return false;
    if(in_array($permission,['users.manage','settings.manage'],true) && $user['role_code']!=='ADMIN') return false;
    static $grants=[];
    $rid=$user['role_id'];
    $grants[$rid] ??= array_column(rows('SELECT p.code FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id WHERE rp.role_id=?',[$rid]),'code');
    return in_array($permission,$grants[$rid],true);
}
function deny(): never {
    if(is_api()) json_response(false,'Access denied.',null,403);
    http_response_code(403); require APP_ROOT.'/auth/unauthorized.php'; exit;
}
function require_user(): array {
    $timeout=max(5,min(480,(int)setting('session_timeout','30')))*60;
    if(isset($_SESSION['last_seen']) && time()-$_SESSION['last_seen']>$timeout) clear_session();
    if(!$user=current_user()) {
        if(is_api()) json_response(false,'Sign in to continue.',null,401);
        redirect_to('auth/login.php');
    }
    if(!str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', '/api/notifications.php')) $_SESSION['last_seen']=time();
    return $user;
}
function require_permission(string $permission): array {
    $user=require_user(); if(!can($permission)) deny(); return $user;
}
