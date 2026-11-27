<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/app.php';require_permission('stock.issue');require_once APP_ROOT.'/includes/requisitions.php';
$id=id_value($_GET['id']??null);$r=request_access($id,(int)current_user()['id']);ensure(in_array($r['status'],['APPROVED','PARTIALLY_ISSUED'],true),'Choose an approved request.');
json_response(true,'Request loaded.',['header'=>array_intersect_key($r,array_flip(['department_id','project_id','warehouse_id'])),'lines'=>array_values(array_filter(request_lines($id),fn($line)=>bccomp($line['remaining_qty'],'0',3)>0))]);
