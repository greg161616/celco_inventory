<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once APP_ROOT . '/includes/inventory.php';
require_once APP_ROOT . '/includes/exports.php';
require_once APP_ROOT . '/includes/material_spreadsheet.php';
require_once APP_ROOT . '/includes/report_definitions.php';

$tag = bin2hex(random_bytes(5));
$username = "report-$tag";
run_sql("INSERT INTO users(username,role_id,password_hash) SELECT ?,id,? FROM roles WHERE code='ADMIN'",
    [$username,password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
$actor = inserted_id();
$_SESSION['user_id'] = $actor;
$category = 'Workbook category ' . $tag;
catalog_save('categories',['name'=>$category]);
catalog_save('units',['code'=>$tag,'name'=>'Metres']);
$material = ['material_code'=>'W-'.$tag,'name'=>'=Literal material name','specification'=>'Spec','category'=>$category,
    'unit'=>$tag,'reorder_level'=>'15.125','is_serialized'=>'0','is_active'=>'1'];
$columns = material_workbook_columns();
$temp = tmpfile();
write_report_workbook($temp, $columns, [$material]);
$file = stream_get_meta_data($temp)['uri'];
$input = read_material_workbook($file, $columns, array_keys($columns));
checked($input === [2=>$material], 'XLSX preserves literal formulas, codes and decimals');
fclose($temp);
$path = tempnam(sys_get_temp_dir(), 'celco-decimal-');
$numeric = new PhpOffice\PhpSpreadsheet\Spreadsheet();
$numeric->getActiveSheet()->setCellValue('A1','Minimum stock')->setCellValue('A2',12345);
try {
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($numeric))->save($path);
    $zip = new ZipArchive();
    $zip->open($path);
    $part = 'xl/worksheets/sheet1.xml';
    $xml = str_replace('<v>12345</v>', '<v>123456789012345.125</v>', $zip->getFromName($part));
    $zip->addFromString($part, $xml);
    $zip->close();
    checked(read_material_workbook($path,$columns)[2]['reorder_level']==='123456789012345.125',
        'numeric XLSX cells retain exact database-scale decimal precision');
} finally {
    $numeric->disconnectWorksheets();
    unlink($path);
}
$expected = static function (array $plan): array {
    $versions = [];
    foreach ($plan as $row => $item) $versions[$row] = $item['version'];
    return $versions;
};
$plan = material_import_plan($input,'create');
checked($plan[2]['action']==='Create' && !scalar('SELECT id FROM materials WHERE material_code=?',[$material['material_code']]),
    'preview validates a new material without writes');
$counts = commit_material_import($input,'create',$expected($plan));
checked($counts['Create']===1,'confirmed import creates material');
checked((int) scalar("SELECT COUNT(*) FROM audit_logs WHERE user_id=? AND action='IMPORT'",[$actor])===1,'import is audited');
$dupe = $material;
$dupe['material_code'] = 'New-' . $tag;
$relative = $dupe;
$relative['material_code'] = strtolower($dupe['material_code']);
$duplicatePlan = material_import_plan([2=>$dupe,3=>$relative], 'create');
checked($duplicatePlan[3]['error']!=='','duplicate workbook codes use database collation');
checked(material_import_plan($input,'create')[2]['error']!=='','add-only imports reject existing codes');
$id = (int) scalar('SELECT id FROM materials WHERE material_code=?',[$material['material_code']]);
$actualExport = row('SELECT * FROM (' . material_export_query() . ') catalog WHERE material_code=?',[$material['material_code']]);
$temp = tmpfile();
write_report_workbook($temp,$columns,[$actualExport]);
$roundtrip = read_material_workbook(stream_get_meta_data($temp)['uri'],$columns,array_keys($columns));
fclose($temp);
$merge = material_import_plan($roundtrip,'merge');
checked(commit_material_import($roundtrip,'merge',$expected($merge))['Unchanged']===1,
    'catalog export can be imported unchanged');
$changed = $material;
$changed['name'] = 'Updated wire';
$rows = [2=>$dupe,3=>$changed];
$merge = material_import_plan($rows,'merge');
run_sql('UPDATE materials SET name=? WHERE id=?',['Concurrent edit',$id]);
rejected(fn()=>commit_material_import($rows,'merge',$expected($merge)),'stale preview prevents update');
checked(!scalar('SELECT id FROM materials WHERE material_code=?',[$dupe['material_code']]),'stale second row rolls back first insert');
$fresh = material_import_plan($rows,'merge');
checked(commit_material_import($rows,'merge',$expected($fresh))===['Create'=>1,'Update'=>1,'Unchanged'=>0],
    'confirmed merge inserts and updates atomically');

$definitions = report_definitions();
checked(count($definitions)===21,'all 21 report definitions exist');
foreach ($definitions as $slug => $definition) {
    $predicates = [];
    foreach ($definition['filters'] as $expressions) {
        $terms = array_map(static fn($expr)=>"$expr = ?", (array)$expressions);
        $predicates[] = '(' . implode(' OR ', $terms) . ')';
    }
    $sql = str_replace('/*FILTERS*/',' AND '.implode(' AND ', $predicates), $definition['sql'])
        . ' ORDER BY ' . $definition['order'] . ' LIMIT 1';
    $statement = run_sql($sql, array_fill(0, substr_count($sql,'?'), null));
    $keys = [];
    for ($i=0; $i<$statement->columnCount(); ++$i) $keys[] = $statement->getColumnMeta($i)['name'];
    checked($keys===array_keys($definition['columns']), "schema and allowed filters for $slug");
}
$department = catalog_save('departments',['code'=>$tag,'name'=>'Report department']);
$employee = catalog_save('employees',['employee_no'=>$tag,'full_name'=>'Report employee','department_id'=>$department]);
$project = catalog_save('projects',['project_code'=>$tag,'name'=>'Report project','department_id'=>$department,'status'=>'ACTIVE']);
$warehouse = catalog_save('warehouses',['code'=>$tag,'name'=>'Report warehouse']);
$header = ['document_date'=>date('Y-m-d'),'remarks'=>'Report test'];
$line = ['material_id'=>$id,'quantity'=>'100.125','to_warehouse_id'=>$warehouse];
$receipt = save_document('RECEIPT',$header,[$line],$actor,doc_number('RECEIPT'));
post_document($receipt,$actor,'RECEIPT');
$line = ['material_id'=>$id,'quantity'=>'30','from_warehouse_id'=>$warehouse];
$header += ['recipient_employee_id'=>$employee,'department_id'=>$department,'project_id'=>$project];
$issue = save_document('ISSUE',$header,[$line],$actor,doc_number('ISSUE'));
post_document($issue,$actor,'ISSUE');
$line = ['material_id'=>$id,'quantity'=>'5','to_warehouse_id'=>$warehouse,
    'return_of_line_id'=>scalar('SELECT id FROM stock_document_lines WHERE document_id=?',[$issue])];
$return = save_document('RETURN',$header,[$line],$actor,doc_number('RETURN'));
post_document($return,$actor,'RETURN');
$result = static function (string $slug) use ($definitions,$id): array {
    $report = $definitions[$slug];
    return rows(str_replace('/*FILTERS*/',' AND '.$report['filters']['material_id'].'=?',$report['sql']),[$id]);
};
checked($result('inventory-summary')[0]['available_qty']==='75.125','inventory report preserves exact available quantity');
checked($result('inventory-reconciliation')[0]['variance']==='0.000','stock reconciliation report matches movements');
checked($result('department-consumption')[0]['net_qty']==='25.000' && $result('project-consumption')[0]['net_qty']==='25.000',
    'consumption reports deduct returns from original issuance');
reverse_document($return,$actor,'Report verification','RETURN');
checked($result('department-consumption')[0]['net_qty']==='30.000','reversing a return restores reported consumption');
$temp = tmpfile();
write_report_pdf($temp,$columns,[$material],['CELCO','Materials','Generated locally'],'');
rewind($temp);
checked(fread($temp,5)==='%PDF-','Dompdf generates a PDF');
fclose($temp);
$temp = tmpfile();
write_report_csv($temp,$columns,[$material]);
rewind($temp);
checked(str_contains(stream_get_contents($temp),"'=Literal material name"),'CSV neutralizes formula-like text');
fclose($temp);
$temp = tmpfile();
$book = new PhpOffice\PhpSpreadsheet\Spreadsheet();
$book->getActiveSheet()->setCellValue('A1','Material name')->setCellValue('A2','=1+1');
(new PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($temp);
try {
    read_material_workbook(stream_get_meta_data($temp)['uri'],$columns);
    throw new RuntimeException('Expected formula validation.');
} catch (DomainException $error) {
    checked(str_contains($error->getMessage(),'formula'),'import requires values for formula cells');
} finally {
    fclose($temp);
    $book->disconnectWorksheets();
}
