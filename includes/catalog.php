<?php
declare(strict_types=1);
// Field definitions are also the allowlist for identifiers used in generated SQL.
function catalogs(): array {
    return [
      'materials'=>['material_code'=>['Material code','code',50],'name'=>['Material name','text',150],'specification'=>['Specifications','memo',6000,false],'category_id'=>['Category','ref','categories'],'unit_id'=>['Unit','ref','units'],'reorder_level'=>['Minimum stock','qty',0],'is_serialized'=>['Serial tracking','bool',0],'is_active'=>['Active','bool',1]],
      'categories'=>['name'=>['Category name','text',150],'description'=>['Description','text',255,false],'is_active'=>['Active','bool',1]],
      'units'=>['code'=>['Unit code','text',20],'name'=>['Unit name','text',150]],
      'suppliers'=>['supplier_code'=>['Supplier code','text',40],'name'=>['Supplier name','text',150],'contact_person'=>['Contact person','text',120,false],'phone'=>['Phone','text',40,false],'email'=>['Email','email',190,false],'address'=>['Address','memo',2000,false],'is_active'=>['Active','bool',1]],
      'warehouses'=>['code'=>['Warehouse code','text',40],'name'=>['Warehouse name','text',150],'location'=>['Location','text',255,false],'is_active'=>['Active','bool',1]],
      'departments'=>['code'=>['Department code','text',30],'name'=>['Department name','text',150],'is_active'=>['Active','bool',1]],
      'employees'=>['employee_no'=>['Employee number','text',40],'full_name'=>['Full name','text',150],'department_id'=>['Department','ref','departments'],'is_active'=>['Active','bool',1]],
      'projects'=>['project_code'=>['Project code','text',50],'name'=>['Project name','text',150],'department_id'=>['Department','ref','departments',false],'status'=>['Status','enum',['PLANNED','ACTIVE','COMPLETED','CANCELLED']],'start_date'=>['Start date','date',0,false],'end_date'=>['End date','date',0,false]]
    ];
}
function catalog_data(string $table,array $input): array {
    $data=[];foreach(catalogs()[$table] as $key=>$def){
        [$label,$type,$arg]=$def;$required=$def[3]??true;$v=$input[$key]??null;
        $data[$key]=match($type){
            'ref'=>active_ref($arg,$v,$required),'bool'=>(int)choice((string)($v??$arg),['0','1']),
            'qty'=>decimal_value($v??'0'),'enum'=>choice($v,$arg),'date'=>empty($v)?null:date_value($v),
            'code'=>string_value($v,$arg,false)??doc_number('MAT'),default=>string_value($v,$arg,$required)
        };
        if($type==='email' && $data[$key]) ensure((bool)filter_var($data[$key],FILTER_VALIDATE_EMAIL),'Enter a valid email address.');
    }
    if($table==='projects') ensure(!$data['start_date']||!$data['end_date']||$data['end_date']>=$data['start_date'],'End date must follow the start date.');
    return $data;
}
function catalog_save(string $table,array $input,?int $id=null): int {
    ensure(isset(catalogs()[$table]),'Unknown catalog.');
    return atomic(function() use($table,$input,$id){
        $old=$id ? row("SELECT * FROM $table WHERE id=? FOR UPDATE",[$id]) : null;
        ensure(!$id || (bool)$old,'Record no longer exists.');
        $data=catalog_data($table,$input);
        if($old && $table==='materials' && ($data['unit_id']!=$old['unit_id']||$data['is_serialized']!=$old['is_serialized'])){
            foreach(['stock_document_lines','stock_count_items','requisition_items','material_serials','inventory_stocks'] as $relation)
                ensure(!scalar("SELECT id FROM $relation WHERE material_id=? LIMIT 1",[$id]),'Unit and tracking mode are locked after use.');
        }
        $names=array_keys($data);
        if($id){run_sql("UPDATE $table SET ".implode('=?,',$names).'=? WHERE id=?',[...array_values($data),$id]);}
        else {run_sql("INSERT INTO $table (".implode(',',$names).') VALUES ('.implode(',',array_fill(0,count($names),'?')).')',array_values($data));$id=inserted_id();}
        audit($old?'UPDATE':'CREATE',$table,$id,['before'=>$old,'after'=>$data]);
        if($table==='materials' && $data['is_active']){
            require_once __DIR__.'/notifications.php';
            notify_stock([$id]);
        }
        return $id;
    });
}
