<?php
declare(strict_types=1);
require_once __DIR__.'/inventory.php';
require_once __DIR__.'/notifications.php';
function create_request(array $input,array $lines,int $actor,string $number): int {
    return atomic(function() use($input,$lines,$actor,$number){
        $requester=id_value($input['requested_by']??$actor);
        ensure($requester===$actor || can('users.manage'),'Only account administrators may enter a request on behalf of another user.');
        $profile=row("SELECT r.code,e.department_id FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN employees e ON e.id=u.employee_id WHERE u.id=? AND u.is_active=1 AND EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=r.id AND p.code='requisition.create')",[$requester]);
        ensure((bool)$profile,'Choose an active requester with permission to request materials.');
        $existing=row('SELECT id,requested_by FROM requisitions WHERE request_no=? FOR UPDATE',[$number]);
        if($existing){ensure($existing['requested_by']==$requester,'Request number already in use.');return (int)$existing['id'];}
        ensure(count($lines)>0 && count($lines)<=150,'Add between one and 150 materials.');
        $department=active_ref('departments',$input['department_id']??null);
        $assigned=$profile['department_id'];
        ensure($profile['code']!=='REQUESTER'||$assigned,'Assign the requester to an employee and department first.');
        ensure(!$assigned || $assigned==$department,'Choose your assigned department.');
        $project=active_ref('projects',$input['project_id']??null,false);
        if($project){$dep=scalar('SELECT department_id FROM projects WHERE id=?',[$project]);ensure(!$dep||$dep==$department,'Project belongs to a different department.');}
        $id=insert_record('requisitions',['request_no'=>$number,'requested_by'=>$requester,'department_id'=>$department,'project_id'=>$project,'warehouse_id'=>active_ref('warehouses',$input['warehouse_id']??null),'purpose'=>string_value($input['purpose']??'',6000)]);
        foreach($lines as $line){
            $m=active_ref('materials',$line['material_id']??null);$q=decimal_value($line['quantity']??null,3,true);
            if(scalar('SELECT is_serialized FROM materials WHERE id=? FOR UPDATE',[$m]))ensure(bcmod($q,'1',3)==='0.000','Serialized material requires a whole quantity.');
            insert_record('requisition_items',['requisition_id'=>$id,'material_id'=>$m,'requested_qty'=>$q,'remarks'=>string_value($line['remarks']??'',255,false)]);
        }
        audit('REQUEST_CREATE','requisitions',$id,['requester'=>$requester],$actor);return $id;
    });
}
function request_transition(int $id,string $action,int $actor,array $input): void {
    atomic(function()use($id,$action,$actor,$input){
        $r=row('SELECT * FROM requisitions WHERE id=? FOR UPDATE',[$id]);ensure((bool)$r,'Request not found.');
        $remarks=string_value($input['remarks']??'',4000,$action==='reject');$entryUser=scalar("SELECT user_id FROM audit_logs WHERE entity_type='requisitions' AND entity_id=? AND action='REQUEST_CREATE' ORDER BY id LIMIT 1",[$id]);
        $owner=$actor==$r['requested_by'] || ($entryUser==$actor && can('users.manage'));
        if($action==='submit'){
            ensure(can('requisition.create') && $owner && $r['status']==='DRAFT','Only the requester can submit a draft.');
            run_sql("UPDATE requisitions SET status='SUBMITTED',submitted_at=NOW() WHERE id=?",[$id]);
            notify_users(recipients('requisition.approve'),'NEW_REQUEST',$r['request_no'].' awaits review.',null,$id);
        }else{
            if($action==='cancel'){ensure(can('requisition.create') && $owner && in_array($r['status'],['DRAFT','SUBMITTED'],true),'Only the requester can cancel an eligible request.');$status='CANCELLED';}
            else{
                ensure(can('requisition.approve') && !$owner && $entryUser!=$actor && $r['status']==='SUBMITTED','A different authorized approver must review a submitted request.');
                $status=choice($action,['approve','reject'])==='approve'?'APPROVED':'REJECTED';
                if($status==='APPROVED'){
                    $some=false;foreach(rows('SELECT i.id,i.requested_qty,m.is_serialized FROM requisition_items i JOIN materials m ON m.id=i.material_id WHERE i.requisition_id=? FOR UPDATE',[$id]) as $i){
                        $q=decimal_value($input['approved'][$i['id']]??null);ensure(bccomp($q,$i['requested_qty'],3)<=0,'Approval exceeds the requested quantity.');
                        ensure(!$i['is_serialized'] || bcmod($q,'1',3)==='0.000','Serialized approval must be a whole quantity.');
                        $some=$some||bccomp($q,'0',3)>0;run_sql('UPDATE requisition_items SET approved_qty=? WHERE id=?',[$q,$i['id']]);
                    }ensure($some,'Approve at least one material or reject the request.');
                }
            }
            run_sql('UPDATE requisitions SET status=? WHERE id=?',[$status,$id]);
            insert_record('requisition_approvals',['requisition_id'=>$id,'decided_by'=>$actor,'decision'=>$status,'remarks'=>$remarks]);
            notify_users([(int)$r['requested_by']],$status,$r['request_no'].' · '.strtolower($status),null,$id);
            if($status==='APPROVED')notify_users(recipients('stock.issue'),'AWAITING_ISSUE',$r['request_no'].' is ready for issuance.',null,$id);
        }
        audit('REQUEST_'.strtoupper($action),'requisitions',$id,['remarks'=>$remarks]);
    });
}
function request_access(int $id,int $actor): array {
    $r=row('SELECT r.*,u.username requester,w.name warehouse,d.name department,p.name project FROM requisitions r JOIN users u ON u.id=r.requested_by JOIN warehouses w ON w.id=r.warehouse_id JOIN departments d ON d.id=r.department_id LEFT JOIN projects p ON p.id=r.project_id WHERE r.id=?',[$id]);
    if(!$r || ($r['requested_by']!=$actor && !can('stock.issue') && !can('requisition.approve')))deny();return $r;
}
function request_lines(int $id): array {
    return rows("SELECT f.*,m.name material,u.code unit,COALESCE(s.quantity,0) available FROM v_requisition_fulfillment f JOIN requisitions r ON r.id=f.requisition_id JOIN materials m ON m.id=f.material_id JOIN units u ON u.id=m.unit_id LEFT JOIN inventory_stocks s ON s.material_id=m.id AND s.stock_condition='GOOD' AND s.warehouse_id=r.warehouse_id WHERE r.id=? ORDER BY f.requisition_item_id",[$id]);
}
