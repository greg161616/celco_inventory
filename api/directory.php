<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/app.php';
require_user();
if(!array_filter(['stock.issue','stock.return','requisition.create','materials.manage'],'can'))deny();
$id=id_value($_GET['department_id']??null,false);
$projects=rows("SELECT id,name label FROM projects WHERE status IN ('ACTIVE','PLANNED') AND (? IS NULL OR department_id IS NULL OR department_id=?) ORDER BY name",[$id,$id]);
$employees=[];
if(can('stock.issue')||can('stock.return')||can('materials.manage'))$employees=rows('SELECT id,full_name label FROM employees WHERE is_active=1 AND (? IS NULL OR department_id=?) ORDER BY full_name',[$id,$id]);
json_response(true,'Department directory loaded.',['project_id'=>$projects,'recipient_employee_id'=>$employees]);
