<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/app.php';require_permission('users.manage');require_once APP_ROOT.'/includes/admin.php';$base='modules/roles/index.php';$id=id_value($_GET['id']??null,false);
post_action(fn()=>$base.'?id='.save_role($_POST,$id),$base.($id?'?id='.$id:'?new=1'));layout_start('Roles and permissions','roles');
if($id||isset($_GET['new'])){
    $r=$id?row('SELECT code,name,description FROM roles WHERE id=?',[$id]):[];ensure(!$id||$r,'Role not found.');
    $grants=$id?array_column(rows('SELECT permission_id FROM role_permissions WHERE role_id=?',[$id]),'permission_id'):[];
    page_link($base,'All roles');echo '<form method="post" class="panel">'.csrf_field().'<div class="form-grid">';if(!$id)field('code','Role code','','text',[],true);field('name','Role name',$r['name']??'','text',[],true);field('description','Description',$r['description']??'');echo '</div><fieldset><legend>Granted permissions</legend><div class="form-grid">';
    foreach(rows('SELECT id,code,description FROM permissions ORDER BY code') as $p)echo '<label><input type="checkbox" name="grants[]" value="'.e($p['id']).'" '.(in_array($p['id'],$grants)?'checked':'').'> '.e($p['description']).'</label>';
    echo '</div></fieldset>';button_action('save','Save role',true);echo '</form>';
}else{page_link($base.'?new=1','New role','btn btn-primary');table_html(['code'=>'Code','name'=>'Role','description'=>'Description','members'=>'Accounts'],rows('SELECT r.id,r.code,r.name,r.description,(SELECT COUNT(*) FROM users u WHERE u.role_id=r.id) members FROM roles r ORDER BY r.name'.page_limit()),fn($r)=>page_link($base.'?id='.$r['id'],'Permissions'));pager((int)scalar('SELECT COUNT(*) FROM roles'));}layout_end();
