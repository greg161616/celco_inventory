<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/app.php';require_permission('inventory.view');layout_start('Serial register','serials');
$base='modules/serials/index.php';$id=id_value($_GET['id']??null,false);
if($id){
    $serial=row('SELECT s.serial_number,m.name,s.state,w.name warehouse,s.stock_condition FROM material_serials s JOIN materials m ON m.id=s.material_id LEFT JOIN warehouses w ON w.id=s.warehouse_id WHERE s.id=?',[$id]);ensure((bool)$serial,'Serial not found.');page_link($base,'Serial register');echo '<h2>'.e($serial['serial_number']).'</h2>';table_html(['name'=>'Material','state'=>'State','warehouse'=>'Warehouse','stock_condition'=>'Condition'],[$serial]);
    table_html(['document_no'=>'Document','document_type'=>'Action','posted_at'=>'Posted','source'=>'From','destination'=>'To'],rows("SELECT d.document_no,d.document_type,d.posted_at,CONCAT(w1.name,' · ',l.from_condition) source,CONCAT(w2.name,' · ',l.to_condition) destination FROM stock_document_line_serials x JOIN stock_document_lines l ON l.id=x.document_line_id JOIN stock_documents d ON d.id=l.document_id LEFT JOIN warehouses w1 ON w1.id=l.from_warehouse_id LEFT JOIN warehouses w2 ON w2.id=l.to_warehouse_id WHERE x.serial_id=? AND d.status='POSTED' ORDER BY d.posted_at DESC,d.id DESC",[$id]));
}else{
    echo '<form class="panel">';field('q','Find material or serial',$_GET['q']??'');echo '<button class="btn btn-light">Search</button></form>';$q='%'.string_value($_GET['q']??'',100,false).'%';$where=' FROM material_serials s JOIN materials m ON m.id=s.material_id LEFT JOIN warehouses w ON w.id=s.warehouse_id WHERE s.serial_number LIKE ? OR m.name LIKE ?';
    table_html(['serial_number'=>'Serial number','name'=>'Material','warehouse'=>'Warehouse','stock_condition'=>'Condition','state'=>'State'],rows('SELECT s.id,s.serial_number,m.name,w.name warehouse,s.stock_condition,s.state'.$where.' ORDER BY s.id DESC'.page_limit(),[$q,$q]),fn($r)=>page_link($base.'?id='.$r['id'],'History'));pager((int)scalar('SELECT COUNT(*)'.$where,[$q,$q]));
}layout_end();
