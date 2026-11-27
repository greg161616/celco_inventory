<?php
declare(strict_types=1);
require_once __DIR__.'/inventory.php';
require_once __DIR__.'/notifications.php';
function warehouse_watermark(int $warehouse): int {
    return (int)scalar('SELECT COALESCE(MAX(v.id),0) FROM stock_movements v JOIN inventory_stocks s ON s.id=v.stock_id WHERE s.warehouse_id=?',[$warehouse]);
}
function open_count(array $input,int $actor,string $number): int {
    return atomic(function()use($input,$actor,$number){
        $w=active_ref('warehouses',$input['warehouse_id']??null);lock_rows('warehouses',[$w]);
        $old=row('SELECT id,counted_by FROM stock_counts WHERE count_no=?',[$number]);if($old){ensure($old['counted_by']==$actor,'Count number already used.');return (int)$old['id'];}
        $id=insert_record('stock_counts',['count_no'=>$number,'warehouse_id'=>$w,'counted_by'=>$actor,'status'=>'COUNTING','count_date'=>date_value($input['count_date']??''),'remarks'=>string_value($input['remarks']??'',6000,false)]);
        $sql="INSERT INTO stock_count_items(stock_count_id,material_id,stock_condition,system_qty)
              SELECT ?,m.id,?,COALESCE(s.quantity,0) FROM materials m LEFT JOIN inventory_stocks s ON s.material_id=m.id AND s.stock_condition=? AND s.warehouse_id=?
              WHERE m.is_active=1 OR s.quantity>0";
        foreach(CONDITIONS as $condition)run_sql($sql,[$id,$condition,$condition,$w]);
        ensure((bool)scalar('SELECT id FROM stock_count_items WHERE stock_count_id=? LIMIT 1',[$id]),'Add materials before starting a count.');
        audit('COUNT_SNAPSHOT','stock_counts',$id,['warehouse_id'=>$w,'movement_id'=>warehouse_watermark($w)]);
        return $id;
    });
}
function record_count_items(int $id,int $actor,array $values): void {
    atomic(function()use($id,$actor,$values){
        $count=row('SELECT counted_by,status FROM stock_counts WHERE id=? FOR UPDATE',[$id]);ensure($count && $count['counted_by']==$actor && $count['status']==='COUNTING','Only the assigned counter may record an open count.');
        foreach($values as $item=>$input){
            if(($input['quantity']??'')==='')continue;
            $line=row('SELECT i.id,i.material_id,i.system_qty,m.is_serialized FROM stock_count_items i JOIN materials m ON m.id=i.material_id WHERE i.id=? AND i.stock_count_id=?',[$item,$id]);ensure((bool)$line,'Invalid count line.');
            $qty=decimal_value($input['quantity']);$names=serial_names($input['serials']??'');ensure($line['is_serialized'] ? bccomp($qty,(string)count($names),3)===0 : !$names,'Enter every observed serial for the counted quantity.');
            $remarks=string_value($input['remarks']??'',255,bccomp($qty,$line['system_qty'],3)!==0);
            run_sql('UPDATE stock_count_items SET counted_qty=?,remarks=? WHERE id=?',[$qty,$remarks,$item]);
            run_sql('DELETE FROM stock_count_item_serials WHERE stock_count_item_id=?',[$item]);
            foreach($names as $name){
                $sid=scalar('SELECT id FROM material_serials WHERE serial_number=? AND material_id=?',[$name,$line['material_id']]);
                if(!$sid)$sid=insert_record('material_serials',['material_id'=>$line['material_id'],'serial_number'=>$name]);
                ensure(!scalar('SELECT x.id FROM stock_count_item_serials x JOIN stock_count_items i ON i.id=x.stock_count_item_id WHERE i.stock_count_id=? AND x.serial_id=?',[$id,$sid]),'Serial recorded on another count line.');
                insert_record('stock_count_item_serials',['stock_count_item_id'=>$item,'serial_id'=>$sid]);
            }
        }audit('COUNT_RECORD','stock_counts',$id);
    });
}
function count_transition(int $id,string $action,int $actor): void {
    atomic(function()use($id,$action,$actor){
        $c=row('SELECT * FROM stock_counts WHERE id=? FOR UPDATE',[$id]);ensure((bool)$c,'Unknown count.');
        if($action==='cancel'){
            ensure(($actor==$c['counted_by']||can('stock_count.approve')) && in_array($c['status'],['COUNTING','REVIEWED'],true),'Count cannot be cancelled.');
            run_sql("UPDATE stock_counts SET status='CANCELLED' WHERE id=?",[$id]);audit('COUNT_CANCEL','stock_counts',$id);return;
        }
        if($action==='submit'){
            ensure(can('stock_count.manage') && $actor==$c['counted_by'] && $c['status']==='COUNTING','Only the counter may submit an open count.');
            ensure(!scalar('SELECT id FROM stock_count_items WHERE stock_count_id=? AND counted_qty IS NULL LIMIT 1',[$id]),'Record every count line, including zero quantities.');
            run_sql("UPDATE stock_counts SET status='REVIEWED' WHERE id=?",[$id]);audit('COUNT_SUBMIT','stock_counts',$id);notify_users(recipients('stock_count.approve'),'COUNT_REVIEW',$c['count_no'].' awaits reconciliation.');return;
        }
        ensure($action==='approve' && can('stock_count.approve') && $actor!=$c['counted_by'] && $c['status']==='REVIEWED','A second authorized reviewer must approve a submitted count.');
        $warehouse=(int)$c['warehouse_id'];lock_rows('warehouses',[$warehouse]);
        $snapshot=scalar("SELECT JSON_UNQUOTE(JSON_EXTRACT(details,'$.movement_id')) FROM audit_logs WHERE action='COUNT_SNAPSHOT' AND entity_type='stock_counts' AND entity_id=? ORDER BY id DESC LIMIT 1",[$id]);
        ensure($snapshot!==false && $snapshot!==null && (int)$snapshot===warehouse_watermark($warehouse),'Movements occurred after the snapshot or its history is unavailable. Cancel this count and recount.');
        $lines=[];$items=rows('SELECT i.*,m.is_serialized FROM stock_count_items i JOIN materials m ON m.id=i.material_id WHERE stock_count_id=? ORDER BY i.material_id,i.id',[$id]);
        foreach($items as $i){
            $system=inventory_balance((int)$i['material_id'],$warehouse,$i['stock_condition']);
            ensure(bccomp($system,$i['system_qty'],3)===0,'Balance changed since snapshot. Recount the warehouse.');
            $changes=[];
            if($i['is_serialized']){
                $stock=array_column(rows("SELECT id,serial_number FROM material_serials WHERE material_id=? AND warehouse_id=? AND stock_condition=? AND state='IN_STOCK'",[$i['material_id'],$warehouse,$i['stock_condition']]),'serial_number','id');
                $seen=array_column(rows('SELECT s.id,s.serial_number FROM material_serials s JOIN stock_count_item_serials x ON x.serial_id=s.id WHERE x.stock_count_item_id=?',[$i['id']]),'serial_number','id');
                ensure(bccomp((string)count($stock),$system,3)===0,'Recorded serials differ from the stock balance. Investigate before adjustment.');
                foreach(['OUT'=>array_diff_key($stock,$seen),'IN'=>array_diff_key($seen,$stock)] as $dir=>$names)if($names)$changes[$dir]=[(string)count($names),array_values($names)];
            }else{
                $diff=bcsub($i['counted_qty'],$system,3);
                if(bccomp($diff,'0',3)!==0)$changes[str_starts_with($diff,'-')?'OUT':'IN']=[ltrim($diff,'-'),[]];
            }
            foreach($changes as $dir=>[$qty,$names]){
                ensure(!empty($i['remarks']),'Explain every quantity or serial discrepancy before approval.');
                $side=$dir==='OUT'?'from':'to';$lines[]=['direction'=>$dir,'material_id'=>$i['material_id'],'quantity'=>$qty,$side.'_warehouse_id'=>$warehouse,$side.'_condition'=>$i['stock_condition'],'serials'=>$names,'remarks'=>$i['remarks']];
            }
        }
        $doc=null;if($lines){
            $doc=save_document('ADJUSTMENT',['document_date'=>date('Y-m-d'),'remarks'=>'Approved count '.$c['count_no'],'external_reference'=>$c['count_no']],$lines,$actor,doc_number('ADJUSTMENT'),true);
            run_sql('UPDATE stock_documents SET approved_by=? WHERE id=?',[$actor,$doc]);post_document($doc,$actor,'ADJUSTMENT');
        }
        run_sql("UPDATE stock_counts SET status='POSTED',approved_by=?,adjustment_document_id=? WHERE id=?",[$actor,$doc,$id]);audit('COUNT_APPROVE','stock_counts',$id,['adjustment_document_id'=>$doc]);
    });
}

