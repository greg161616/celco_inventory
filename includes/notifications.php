<?php
declare(strict_types=1);
function notify_users(array $ids,string $type,string $message,?int $material=null,?int $request=null): void {
    foreach(array_unique($ids) as $id)run_sql('INSERT INTO notifications(user_id,material_id,requisition_id,type,message) SELECT ?,?,?,?,? FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM notifications WHERE user_id=? AND material_id<=>? AND requisition_id<=>? AND type=? AND message=?)',[$id,$material,$request,$type,$message,$id,$material,$request,$type,$message]);
}
function recipients(string $permission): array {
    return array_column(rows('SELECT u.id FROM users u JOIN role_permissions rp ON rp.role_id=u.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.code=? AND u.is_active=1',[$permission]),'id');
}
function notify_stock(array $materials): void {
    $ids=recipients('stock.receive');
    foreach($materials as $material){
        $a=row('SELECT name,available_qty,reorder_level FROM v_material_availability WHERE material_id=?',[$material]);
        $out=bccomp($a['available_qty'],'0',3)===0;
        $conditions=['OUT_OF_STOCK'=>$out,'LOW_STOCK'=>!$out && bccomp($a['available_qty'],$a['reorder_level'],3)<=0,'DAMAGED'=>(bool)scalar("SELECT id FROM inventory_stocks WHERE material_id=? AND stock_condition<>'GOOD' AND quantity>0 LIMIT 1",[$material])];
        foreach($conditions as $type=>$open){
            if(!$open){run_sql("UPDATE notifications SET type=CONCAT(type,'_RESOLVED') WHERE material_id=? AND type=?",[$material,$type]);continue;}
            if($type!=='DAMAGED' && setting('low_stock_alerts','1')!=='1')continue;
            notify_users($ids,$type,$a['name'].' · '.strtolower(str_replace('_',' ',$type)),(int)$material);
        }
    }
}
function recent_notifications(int $user): array {
    return ['unread'=>(int)scalar('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL',[$user]),
        'items'=>rows('SELECT id,type title,message,(read_at IS NOT NULL) is_read FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 6',[$user])];
}

