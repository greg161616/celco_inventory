<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/requisitions.php';
require_once APP_ROOT . '/includes/counts.php';

$name = getenv('CELCO_QA_USER');
$password = getenv('CELCO_QA_PASSWORD');
ensure(is_string($name) && str_starts_with($name,'browser-') && is_string($password) && strlen($password) >= 24,
    'Run this setup through tests/browser.mjs.');
$hash = password_hash($password,PASSWORD_DEFAULT);
run_sql("INSERT INTO users(username,password_hash,role_id) SELECT ?,?,id FROM roles WHERE code='ADMIN'", [$name,$hash]);
$actor = inserted_id();
$_SESSION = ['user_id'=>$actor,'credential_version'=>hash('sha256',$hash)];
$tag = substr($name,8);
$category = catalog_save('categories',['name'=>"Browser category $tag"]);
$unit = catalog_save('units',['name'=>'Metres','code'=>$tag]);
$material = catalog_save('materials',['material_code'=>"WIRE-$tag",'name'=>'Insulated wire','category_id'=>$category,'unit_id'=>$unit]);
$warehouse = catalog_save('warehouses',['code'=>$tag,'name'=>"Browser warehouse $tag"]);
$department = catalog_save('departments',['code'=>$tag,'name'=>"Browser department $tag"]);
$receipt = save_document('RECEIPT',['document_date'=>date('Y-m-d')],[
    ['material_id'=>$material,'quantity'=>'100','to_warehouse_id'=>$warehouse]
],$actor,doc_number('RECEIPT'));
post_document($receipt,$actor,'RECEIPT');
$request = create_request(['purpose'=>'Field maintenance','warehouse_id'=>$warehouse,'department_id'=>$department],
    [['material_id'=>$material,'quantity'=>'5']], $actor, doc_number('REQ'));
$count = open_count(['warehouse_id'=>$warehouse,'count_date'=>date('Y-m-d')],$actor,doc_number('COUNT'));
$role = (int) scalar("SELECT id FROM roles WHERE code='ADMIN'");
echo json_encode(compact('actor','category','unit','material','warehouse','department','receipt','request','count','role'),JSON_THROW_ON_ERROR);
