<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/app.php';require_user();
if(!array_filter(['inventory.view','stock.receive','stock.issue','stock.return','stock.transfer','stock.adjust','stock.dispose','stock_count.manage','requisition.create'], 'can'))deny();
$q=string_value($_GET['q']??'',100,false)??'';$kind=$_GET['kind']??'material';
$data=match($kind){
'material'=>rows("SELECT id,CONCAT(material_code,' · ',name) label FROM materials WHERE is_active=1 AND (material_code LIKE ? OR name LIKE ?) ORDER BY name LIMIT 40",['%'.$q.'%','%'.$q.'%']),
'serial'=>rows("SELECT serial_number id,CONCAT(serial_number,' · ',state) label FROM material_serials WHERE material_id=? AND serial_number LIKE ? AND (? IS NULL OR warehouse_id=?) ORDER BY serial_number LIMIT 50",[id_value($_GET['material']??null),'%'.$q.'%',id_value($_GET['warehouse']??null,false),id_value($_GET['warehouse']??null,false)]),
'stock'=>rows('SELECT w.name warehouse,s.stock_condition,s.quantity,u.code unit FROM inventory_stocks s JOIN warehouses w ON w.id=s.warehouse_id JOIN materials m ON m.id=s.material_id JOIN units u ON u.id=m.unit_id WHERE s.material_id=? AND (? IS NULL OR s.warehouse_id=?)',[id_value($_GET['material']??null),id_value($_GET['warehouse']??null,false),id_value($_GET['warehouse']??null,false)]),
default=>throw new InputError('Unknown search.')
};json_response(true,'Search complete.',$data);
