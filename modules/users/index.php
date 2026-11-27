<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/app.php';require_permission('users.manage');require_once APP_ROOT.'/includes/admin.php';$base='modules/users/index.php';$id=id_value($_GET['id']??null,false);
post_action(fn()=>$base.'?id='.save_account($_POST,$id),$base.($id?'?id='.$id:'?new=1'));layout_start('User accounts','users');
if($id || isset($_GET['new'])){
    $r=$id?row('SELECT id,username,role_id,employee_id,is_active,email,last_login_at FROM users WHERE id=?',[$id]):[];ensure(!$id||$r,'Account not found.');page_link($base,'All accounts');
    echo '<form class="panel" method="post">'.csrf_field().'<div class="form-grid">';field('username','Username',$r['username']??'','text',[],true);field('email','Email',$r['email']??'','email');field('employee_id','Assigned employee',$r['employee_id']??'','select',options_for('employees'));field('role_id','Role',$r['role_id']??'','select',options_for('roles'),true);field('is_active','Account status',$r['is_active']??1,'select',[1=>'Active',0=>'Inactive'],true);field('password',$id?'Reset password (optional)':'Initial password','','password',[],!$id);echo '</div>';button_action('save','Save account',true);echo '</form>';
    if($id){echo '<h2>Recent activity</h2>';table_html(['created_at'=>'Time','action'=>'Action','entity_type'=>'Entity'],rows('SELECT created_at,action,entity_type FROM audit_logs WHERE user_id=? ORDER BY id DESC LIMIT 20',[$id]));}
}else{
    page_link($base.'?new=1','New account','btn btn-primary');echo '<form class="panel">';field('q','Search accounts',$_GET['q']??'');echo '<button class="btn btn-light">Search</button></form>';$q='%'.string_value($_GET['q']??'',80,false).'%';
    table_html(['username'=>'Username','role'=>'Role','employee'=>'Employee','active'=>'Status','last_login_at'=>'Last sign-in'],rows("SELECT u.id,username,r.name role,e.full_name employee,IF(u.is_active,'Active','Inactive') active,last_login_at FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN employees e ON e.id=u.employee_id WHERE username LIKE ? ORDER BY username".page_limit(),[$q]),fn($r)=>page_link($base.'?id='.$r['id'],'Manage'));pager((int)scalar('SELECT COUNT(*) FROM users WHERE username LIKE ?',[$q]));
}layout_end();
