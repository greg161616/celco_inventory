<?php
declare(strict_types=1);
const CONDITIONS=['GOOD','DAMAGED','DEFECTIVE','SCRAP'];
const DOCUMENT_PERMISSIONS=['OPENING'=>'stock.adjust','RECEIPT'=>'stock.receive','ISSUE'=>'stock.issue','RETURN'=>'stock.return','TRANSFER'=>'stock.transfer','ADJUSTMENT'=>'stock.adjust','DISPOSAL'=>'stock.dispose','REVERSAL'=>'stock.reverse'];
function submission_field(string $kind): string {
    $number=doc_number($kind);
    return '<input type="hidden" name="number" value="'.e($number).'"><input type="hidden" name="signature" value="'.hash_hmac('sha256',$kind.'|'.$number,csrf_token()).'">';
}
function submitted_number(string $kind,array $input): string {
    $number=string_value($input['number']??'',50);
    ensure(hash_equals(hash_hmac('sha256',$kind.'|'.$number,csrf_token()),(string)($input['signature']??'')),'This draft form expired. Reload the form.');
    return $number;
}
function serial_names(mixed $value): array {
    $items=is_array($value)?$value:preg_split('/[\r\n,]+/',(string)$value);
    $names=[];foreach($items as $item){$name=string_value($item,100,false);if($name!==null)$names[]=$name;}
    ensure(count($names)===count(array_unique(array_map('mb_strtolower',$names))),'A serial number appears twice.');
    return $names;
}
function document_line(string $type,array $line): array {
    $material=active_ref('materials',$line['material_id']??null);
    $qty=decimal_value($line['quantity']??null,3,true);
    $from=in_array($type,['ISSUE','TRANSFER','DISPOSAL'],true) || ($type==='ADJUSTMENT' && ($line['direction']??'')==='OUT');
    $to=in_array($type,['OPENING','RECEIPT','RETURN','TRANSFER'],true) || ($type==='ADJUSTMENT' && ($line['direction']??'')==='IN');
    ensure($from||$to,'Choose an adjustment direction.');
    $src=$from?active_ref('warehouses',$line['from_warehouse_id']??null):null;
    $dst=$to?active_ref('warehouses',$line['to_warehouse_id']??null):null;
    $sc=$from?choice($line['from_condition']??'GOOD',CONDITIONS):null;
    $dc=$to?choice($line['to_condition']??'GOOD',CONDITIONS):null;
    ensure($type!=='ISSUE'||$sc==='GOOD','Only good stock can be issued.');
    ensure($type!=='DISPOSAL'||$sc!=='GOOD','Inspect and reclassify good stock before disposal.');
    ensure(!$src||!$dst||$src!==$dst||$sc!==$dc,'Choose different stock locations or conditions.');
    $cost=($line['unit_cost']??'')!==''?decimal_value($line['unit_cost'],4):null;
    $request=$type==='ISSUE'?id_value($line['requisition_item_id']??null,false):null;
    $original=$type==='RETURN'?id_value($line['return_of_line_id']??null):null;
    return ['material_id'=>$material,'quantity'=>$qty,'unit_cost'=>$cost,'from_warehouse_id'=>$src,'from_condition'=>$sc,'to_warehouse_id'=>$dst,'to_condition'=>$dc,'requisition_item_id'=>$request,'return_of_line_id'=>$original,'remarks'=>string_value($line['remarks']??null,255,false)];
}
function insert_record(string $table,array $data): int {
    run_sql("INSERT INTO $table (".implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));
    return inserted_id();
}
function save_document(string $type,array $header,array $lines,int $actor,string $number,bool $countAdjustment=false): int {
    ensure(isset(DOCUMENT_PERMISSIONS[$type]) && $type!=='REVERSAL','Invalid transaction type.');
    return atomic(function()use($type,$header,$lines,$actor,$number,$countAdjustment){
        if($existing=row('SELECT id,created_by,document_type FROM stock_documents WHERE document_no=? FOR UPDATE',[$number])){
            ensure($existing['created_by']==$actor && $existing['document_type']===$type,'Document number already used.');return (int)$existing['id'];
        }
        ensure(count($lines)>0 && (count($lines)<=150 || ($countAdjustment && $type==='ADJUSTMENT')),'Add between one and 150 material lines.');
        $employee=active_ref('employees',$header['recipient_employee_id']??null,in_array($type,['ISSUE','RETURN'],true));
        $department=active_ref('departments',$header['department_id']??null,$type==='ISSUE');
        $project=active_ref('projects',$header['project_id']??null,false);
        if($employee && $department)ensure(scalar('SELECT department_id FROM employees WHERE id=?',[$employee])==$department,'Employee belongs to another department.');
        if($project && $department){$dep=scalar('SELECT department_id FROM projects WHERE id=?',[$project]);ensure(!$dep||$dep==$department,'Project belongs to another department.');}
        $id=insert_record('stock_documents',['document_no'=>string_value($number,50),'document_type'=>$type,'document_date'=>date_value($header['document_date']??''),'created_by'=>$actor,'supplier_id'=>active_ref('suppliers',$header['supplier_id']??null,false),'recipient_employee_id'=>$employee,'department_id'=>$department,'project_id'=>$project,'external_reference'=>string_value($header['external_reference']??'',100,false),'remarks'=>string_value($header['remarks']??'',6000,in_array($type,['RETURN','DISPOSAL','ISSUE','ADJUSTMENT'],true))]);
        $used=[];$requestId=null;
        foreach($lines as $input){
            $line=document_line($type,$input);
            if($line['requisition_item_id']){
                $req=scalar('SELECT requisition_id FROM requisition_items WHERE id=?',[$line['requisition_item_id']]);
                ensure($req && (!$requestId || $req==$requestId),'Choose items on the same requisition.');$requestId=$req;
            }
            $lid=insert_record('stock_document_lines',['document_id'=>$id]+$line);
            $serialized=scalar('SELECT is_serialized FROM materials WHERE id=? FOR UPDATE',[$line['material_id']]);
            $names=serial_names($input['serials']??'');
            ensure($serialized ? bccomp($line['quantity'],(string)count($names),3)===0 : !$names,'Serial count must match the whole-number quantity for tracked material.');
            foreach($names as $name){
                $sid=scalar('SELECT id FROM material_serials WHERE material_id=? AND serial_number=?',[$line['material_id'],$name]);
                if(!$sid && !$line['from_warehouse_id'] && $type!=='RETURN') $sid=insert_record('material_serials',['material_id'=>$line['material_id'],'serial_number'=>$name]);
                ensure((bool)$sid && !isset($used[$sid]),'Invalid or repeated serial number on this document.');
                $used[$sid]=true;insert_record('stock_document_line_serials',['document_line_id'=>$lid,'serial_id'=>$sid]);
            }
        }
        if($requestId) ensure(!scalar('SELECT COUNT(*) FROM stock_document_lines WHERE document_id=? AND requisition_item_id IS NULL',[$id]),'Use separate documents for direct and requisition issues.');
        audit('DRAFT_'.$type,'stock_documents',$id);return $id;
    });
}
function stock_snapshot(int $stockId): array {
    return row('SELECT recorded_qty,ledger_qty,variance FROM v_stock_reconciliation WHERE stock_id=?',[$stockId])??[];
}
function inventory_balance(int $material,int $warehouse,string $condition='GOOD'): string {
    return (string)(scalar('SELECT quantity FROM inventory_stocks WHERE material_id=? AND warehouse_id=? AND stock_condition=?',[$material,$warehouse,$condition])?:'0.000');
}
function stock_locations(array $lines): array {
    $locations=[];
    foreach($lines as $line)foreach(['from','to'] as $side)if($w=$line[$side.'_warehouse_id']){
        $key=stock_key($line,$side);$locations[$key]=[$line['material_id'],$w,$line[$side.'_condition']];
    }
    ksort($locations,SORT_STRING);return $locations;
}
function stock_key(array $line,string $side): string {
    return sprintf('%020d:%020d:%s',$line['material_id'],$line[$side.'_warehouse_id'],$line[$side.'_condition']);
}
function lock_stocks(array $lines): array {
    $ids=[];foreach(stock_locations($lines) as $key=>$tuple){
        run_sql('INSERT INTO inventory_stocks(material_id,warehouse_id,stock_condition) VALUES(?,?,?) ON DUPLICATE KEY UPDATE id=id',$tuple);
        $id=(int)scalar('SELECT id FROM inventory_stocks WHERE material_id=? AND warehouse_id=? AND stock_condition=? FOR UPDATE',$tuple);
        ensure(bccomp(stock_snapshot($id)['variance'],'0',3)===0,'Stock and ledger differ. Reconcile before posting.');
        $ids[$key]=$id;
    }return $ids;
}
function move_stock(int $line,int $stock,string $direction,string $qty): void {
    $delta=$direction==='OUT'?'-'.$qty:$qty;
    $balance=(string)scalar('SELECT quantity FROM inventory_stocks WHERE id=? FOR UPDATE',[$stock]);
    $after=bcadd($balance,$delta,3);
    ensure(bccomp($after,'0',3)>=0,'Insufficient stock in the selected warehouse and condition.');
    run_sql('UPDATE inventory_stocks SET quantity=? WHERE id=?',[$after,$stock]);
    insert_record('stock_movements',['document_line_id'=>$line,'stock_id'=>$stock,'direction'=>$direction,'quantity_delta'=>$delta,'balance_after'=>$after]);
}
function serial_state(array $line,string $side,string $outside): array {
    return $line[$side.'_warehouse_id'] ? ['IN_STOCK',$line[$side.'_warehouse_id'],$line[$side.'_condition']] : [$outside,null,null];
}
function move_serials(array $line,string $type,?string $originalType=null): void {
    $serials=rows('SELECT s.id,s.state,s.warehouse_id,s.stock_condition,s.material_id FROM stock_document_line_serials x JOIN material_serials s ON s.id=x.serial_id WHERE x.document_line_id=? ORDER BY s.id FOR UPDATE',[$line['id']]);
    $tracked=scalar('SELECT is_serialized FROM materials WHERE id=?',[$line['material_id']]);
    ensure($tracked ? bccomp((string)count($serials),$line['quantity'],3)===0 : !$serials,'Quantity and serial assignments differ.');
    $before=match($type){'RETURN'=>'ISSUED','REVERSAL'=>($originalType==='ISSUE'?'ISSUED':'DISPOSED'),default=>'PENDING'};
    $after=match($type){'ISSUE'=>'ISSUED','REVERSAL'=>($originalType==='RETURN'?'ISSUED':'PENDING'),default=>'DISPOSED'};
    [$expect,$warehouse,$condition]=serial_state($line,'from',$before);
    [$state,$destination,$newCondition]=serial_state($line,'to',$after);
    foreach($serials as $s){
        ensure($s['material_id']==$line['material_id'] && $s['state']===$expect && $s['warehouse_id']==$warehouse && $s['stock_condition']===$condition,'Serial number is unavailable at the expected location.');
        if($type==='RETURN'){
            ensure((bool)scalar('SELECT id FROM stock_document_line_serials WHERE document_line_id=? AND serial_id=?',[$line['return_of_line_id'],$s['id']]),'Serial was not on the original issue.');
            $latest=scalar("SELECT l.id FROM stock_document_line_serials x JOIN stock_document_lines l ON l.id=x.document_line_id JOIN stock_documents d ON d.id=l.document_id LEFT JOIN stock_documents r ON r.reverses_document_id=d.id AND r.status='POSTED' WHERE x.serial_id=? AND d.status='POSTED' AND d.document_type<>'REVERSAL' AND r.id IS NULL ORDER BY (SELECT MAX(v.id) FROM stock_movements v WHERE v.document_line_id=l.id) DESC LIMIT 1",[$s['id']]);
            ensure($latest==$line['return_of_line_id'],'Serial has a later issue.');
        }
        run_sql('UPDATE material_serials SET state=?,warehouse_id=?,stock_condition=? WHERE id=?',[$state,$destination,$newCondition,$s['id']]);
    }
}
function validate_original_issue(array $line,array $doc): void {
    $source=row("SELECT l.material_id,l.quantity,d.id,d.document_type,d.status,d.recipient_employee_id FROM stock_document_lines l JOIN stock_documents d ON d.id=l.document_id WHERE l.id=? FOR UPDATE",[$line['return_of_line_id']]);
    ensure($source && $source['document_type']==='ISSUE' && $source['status']==='POSTED' && $source['material_id']==$line['material_id'],'Choose a posted issue line for this material.');
    ensure(!scalar("SELECT id FROM stock_documents WHERE reverses_document_id=? AND status='POSTED'",[$source['id']]),'The original issue has been reversed.');
    $returned=scalar("SELECT COALESCE(SUM(l.quantity),0) FROM stock_document_lines l JOIN stock_documents d ON d.id=l.document_id LEFT JOIN stock_documents r ON r.reverses_document_id=d.id AND r.status='POSTED' WHERE l.return_of_line_id=? AND (d.status='POSTED' OR d.id=?) AND r.id IS NULL",[$line['return_of_line_id'],$doc['id']]);
    ensure(bccomp($returned,$source['quantity'],3)<=0,'Cumulative returns exceed the original issue.');
}
function validate_issuance(array $line,array $doc): int {
    $item=row('SELECT ri.material_id,r.id,r.status,r.warehouse_id,r.department_id,r.project_id FROM requisition_items ri JOIN requisitions r ON r.id=ri.requisition_id WHERE ri.id=? FOR UPDATE',[$line['requisition_item_id']]);
    ensure($item && in_array($item['status'],['APPROVED','PARTIALLY_ISSUED'],true) && $item['material_id']==$line['material_id'] && $item['warehouse_id']==$line['from_warehouse_id'] && $item['department_id']==$doc['department_id'] && $item['project_id']==$doc['project_id'],'Requisition, warehouse, project, department and material must match an approved request.');
    $remaining=scalar('SELECT remaining_qty FROM v_requisition_fulfillment WHERE requisition_item_id=?',[$line['requisition_item_id']]);
    $total=scalar('SELECT SUM(quantity) FROM stock_document_lines WHERE document_id=? AND requisition_item_id=?',[$doc['id'],$line['requisition_item_id']]);
    ensure($remaining!==null && bccomp($total,$remaining,3)<=0,'Issue exceeds remaining approved quantity.');
    return (int)$item['id'];
}
function refresh_fulfillment(int $request): void {
    $s=row('SELECT SUM(remaining_qty) remaining,SUM(issued_qty) issued FROM v_requisition_fulfillment WHERE requisition_id=?',[$request]);
    $status=bccomp($s['remaining'],'0',3)===0?'ISSUED':(bccomp($s['issued'],'0',3)>0?'PARTIALLY_ISSUED':'APPROVED');
    run_sql('UPDATE requisitions SET status=? WHERE id=?',[$status,$request]);
}
function post_document(int $id,int $actor,?string $expectedType=null): void {
    atomic(function()use($id,$actor,$expectedType){
        $doc=row('SELECT * FROM stock_documents WHERE id=? FOR UPDATE',[$id]);
        ensure($doc && (!$expectedType||$doc['document_type']===$expectedType),'Document not found in this module.');
        if($doc['status']==='POSTED')return;
        ensure($doc['status']==='DRAFT','Only a draft can be posted.');$type=$doc['document_type'];
        if($type==='DISPOSAL')ensure($doc['created_by']!=$actor,'A second authorized officer must approve disposal.');
        $lines=rows('SELECT * FROM stock_document_lines WHERE document_id=? ORDER BY id FOR UPDATE',[$id]);ensure((bool)$lines,'Add a material line.');
        $warehouses=[...array_column($lines,'from_warehouse_id'),...array_column($lines,'to_warehouse_id')];lock_rows('warehouses',$warehouses);
        lock_rows('materials',array_column($lines,'material_id'));
        foreach(array_filter(array_unique($warehouses)) as $w)active_ref('warehouses',$w);
        foreach(array_unique(array_column($lines,'material_id')) as $m)active_ref('materials',$m);
        $original=$type==='REVERSAL'?scalar('SELECT document_type FROM stock_documents WHERE id=?',[$doc['reverses_document_id']]):null;
        $stocks=lock_stocks($lines);$requests=[];
        foreach($lines as $line){
            if($type==='ISSUE' && $line['requisition_item_id'])$requests[]=validate_issuance($line,$doc);
            if($type==='RETURN')validate_original_issue($line,$doc);
            move_serials($line,$type,$original?:null);
            foreach(['from'=>'OUT','to'=>'IN'] as $side=>$dir)if($line[$side.'_warehouse_id'])move_stock((int)$line['id'],$stocks[stock_key($line,$side)],$dir,$line['quantity']);
        }
        run_sql("UPDATE stock_documents SET status='POSTED',posted_by=?,posted_at=NOW(),approved_by=IF(document_type='DISPOSAL',?,approved_by) WHERE id=?",[$actor,$actor,$id]);
        foreach(array_unique($requests) as $request)refresh_fulfillment($request);
        audit('POST_'.$type,'stock_documents',$id,['number'=>$doc['document_no']]);
        if(function_exists('notify_stock'))notify_stock(array_unique(array_column($lines,'material_id')));
    });
}
function reverse_document(int $id,int $actor,string $reason,?string $expectedType=null): int {
    return atomic(function()use($id,$actor,$reason,$expectedType){
        $doc=row('SELECT * FROM stock_documents WHERE id=? FOR UPDATE',[$id]);
        ensure($doc && $doc['status']==='POSTED' && $doc['document_type']!=='REVERSAL' && (!$expectedType||$doc['document_type']===$expectedType),'Choose a posted original document.');
        ensure(!scalar('SELECT id FROM stock_documents WHERE reverses_document_id=?',[$id]),'Document already reversed.');
        ensure(!scalar('SELECT id FROM stock_counts WHERE adjustment_document_id=?',[$id]),'Use another reviewed count to correct a posted physical count.');
        $lines=rows('SELECT * FROM stock_document_lines WHERE document_id=? ORDER BY id DESC',[$id]);
        lock_rows('warehouses',[...array_column($lines,'from_warehouse_id'),...array_column($lines,'to_warehouse_id')]);
        // Conservatively reverse in ledger order so later consumption or serial movements cannot be invalidated.
        ensure(!scalar('SELECT 1 FROM stock_document_lines l JOIN stock_movements v ON v.document_line_id=l.id JOIN stock_movements later ON later.stock_id=v.stock_id AND later.id>v.id JOIN stock_document_lines ll ON ll.id=later.document_line_id WHERE l.document_id=? AND ll.document_id<>? LIMIT 1',[$id,$id]),'Later movements exist at this stock location. Review dependent transactions.');
        ensure(!scalar("SELECT 1 FROM stock_document_lines src JOIN stock_document_lines child ON child.return_of_line_id=src.id JOIN stock_documents d ON d.id=child.document_id WHERE src.document_id=? AND d.status='POSTED' AND NOT EXISTS(SELECT 1 FROM stock_documents r WHERE r.reverses_document_id=d.id AND r.status='POSTED') LIMIT 1",[$id]),'An outstanding return depends on this issue.');
        $new=insert_record('stock_documents',['document_no'=>doc_number('REVERSAL'),'document_type'=>'REVERSAL','document_date'=>date('Y-m-d'),'created_by'=>$actor,'reverses_document_id'=>$id,'remarks'=>string_value($reason,6000)]);
        $requests=[];foreach($lines as $l){
            $line=insert_record('stock_document_lines',['document_id'=>$new,'material_id'=>$l['material_id'],'quantity'=>$l['quantity'],'unit_cost'=>$l['unit_cost'],'from_warehouse_id'=>$l['to_warehouse_id'],'from_condition'=>$l['to_condition'],'to_warehouse_id'=>$l['from_warehouse_id'],'to_condition'=>$l['from_condition'],'reverses_line_id'=>$l['id']]);
            run_sql('INSERT INTO stock_document_line_serials(document_line_id,serial_id) SELECT ?,serial_id FROM stock_document_line_serials WHERE document_line_id=?',[$line,$l['id']]);
            if($l['requisition_item_id'])$requests[]=(int)scalar('SELECT requisition_id FROM requisition_items WHERE id=? FOR UPDATE',[$l['requisition_item_id']]);
        }
        lock_rows('requisitions',$requests);
        post_document($new,$actor,'REVERSAL');foreach(array_unique($requests) as $r)refresh_fulfillment($r);return $new;
    });
}
