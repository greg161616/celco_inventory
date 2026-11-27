<?php
declare(strict_types=1);
require_once __DIR__.'/inventory.php';
function save_account(array $input,?int $id): int {
    return atomic(function()use($input,$id){
        $admin=(int)scalar("SELECT id FROM roles WHERE code='ADMIN' FOR UPDATE");
        $old=$id?row('SELECT id,username,role_id,employee_id,email,is_active FROM users WHERE id=? FOR UPDATE',[$id]):null;ensure(!$id||$old,'Unknown account.');
        $role=active_ref('roles',$input['role_id']??null);$employee=active_ref('employees',$input['employee_id']??'',false);
        $active=(int)choice((string)($input['is_active']??''),['0','1']);$email=string_value($input['email']??'',190,false);
        ensure(!$email || filter_var($email,FILTER_VALIDATE_EMAIL)!==false,'Enter a valid email address.');
        if(scalar('SELECT code FROM roles WHERE id=?',[$role])==='REQUESTER')ensure($employee!==null,'Assign an employee so the requester has a department.');
        if($old && $old['role_id']==$admin && $old['is_active'] && (!$active || $role!==$admin)){
            ensure((bool)scalar('SELECT id FROM users WHERE role_id=? AND is_active=1 AND id<>? LIMIT 1',[$admin,$id]),'Keep at least one active administrator.');
        }
        $data=['role_id'=>$role,'employee_id'=>$employee,'username'=>string_value($input['username']??'',80),'email'=>$email,'is_active'=>$active];$values=$data;
        if(!$id || ($input['password']??'')!=='')$values['password_hash']=password_hash(password_value($input['password']??''),PASSWORD_DEFAULT);
        if($id)run_sql('UPDATE users SET '.implode('=?,',array_keys($values)).'=? WHERE id=?',[...array_values($values),$id]);else $id=insert_record('users',$values);
        audit($old?'USER_UPDATE':'USER_CREATE','users',$id,['before'=>$old,'after'=>$data,'password_changed'=>isset($values['password_hash'])]);return $id;
    });
}
function save_role(array $input,?int $id): int {
    return atomic(function()use($input,$id){
        $admin=(int)scalar("SELECT id FROM roles WHERE code='ADMIN' FOR UPDATE");
        $role=$id?row('SELECT code,name,description FROM roles WHERE id=? FOR UPDATE',[$id]):null;ensure(!$id||$role,'Unknown role.');
        $code=$role['code']??strtoupper(string_value($input['code']??'',40));ensure((bool)preg_match('/^[A-Z][A-Z0-9_]{0,39}$/D',$code),'Enter an uppercase role code.');
        $name=string_value($input['name']??'',150);$description=string_value($input['description']??'',255,false);
        $perms=rows('SELECT id,code FROM permissions ORDER BY id');$byId=array_column($perms,'code','id');$grants=array_unique(array_map('id_value',$input['grants']??[]));
        foreach($grants as $grant){ensure(isset($byId[$grant]) && can($byId[$grant]),'Cannot grant a permission you do not hold.');if($id!==$admin)ensure(!in_array($byId[$grant],['users.manage','settings.manage'],true),'Account and settings administration require the administrator role.');}
        if($id===$admin)ensure(count($grants)===count($byId),'The administrator role must retain its full recovery permissions.');
        $before=$id?array_column(rows('SELECT permission_id FROM role_permissions WHERE role_id=?',[$id]),'permission_id'):[];
        if($id)run_sql('UPDATE roles SET name=?,description=? WHERE id=?',[$name,$description,$id]);
        else $id=insert_record('roles',['code'=>$code,'name'=>$name,'description'=>$description]);
        run_sql('DELETE FROM role_permissions WHERE role_id=?',[$id]);foreach($grants as $grant)insert_record('role_permissions',['role_id'=>$id,'permission_id'=>$grant]);
        audit('ROLE_SAVE','roles',$id,['before'=>$role,'previous_permissions'=>$before,'name'=>$name,'permissions'=>$grants]);return $id;
    });
}

