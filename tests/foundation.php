<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$tag=bin2hex(random_bytes(5));$pass=bin2hex(random_bytes(15));$name="qa-$tag";
run_sql("INSERT INTO users(username,role_id,password_hash) SELECT ?,id,? FROM roles WHERE code='ADMIN'",[$name,password_hash($pass,PASSWORD_DEFAULT)]);
checked(!attempt_login($name,'incorrect'),'invalid login');checked(attempt_login($name,$pass),'valid login');
checked(can('materials.manage') && can('settings.manage'),'seeded admin permissions');
$c=catalog_save('categories',['name'=>"Category $tag"]);$u=catalog_save('units',['code'=>$tag,'name'=>'Meters']);
$input=['name'=>'Test wire','material_code'=>$tag,'unit_id'=>$u,'category_id'=>$c];
$m=catalog_save('materials',$input);checked($m>0,'material creation');rejected(fn()=>catalog_save('materials',$input),'duplicate material code');
foreach(['suppliers'=>['supplier_code'=>$tag,'name'=>'Supplier'],'warehouses'=>['code'=>$tag,'name'=>'Warehouse'],'departments'=>['code'=>$tag,'name'=>'Department']] as $table=>$values){$ids[$table]=catalog_save($table,$values);checked($ids[$table]>0,$table);}
checked(catalog_save('employees',['employee_no'=>$tag,'full_name'=>'Employee','department_id'=>$ids['departments']])>0,'employee creation');
checked(catalog_save('projects',['project_code'=>$tag,'name'=>'Work order','status'=>'ACTIVE'])>0,'project creation');
checked(e('<script>')==='&lt;script&gt;','HTML output escaping');
require_once APP_ROOT.'/includes/dashboard.php';checked(count(dashboard_data([])['charts'])===6,'dashboard queries');
checked(row('SELECT id FROM users WHERE username=?',["x' OR '1'='1"])===null,'prepared username lookup');

