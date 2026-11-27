<?php
declare(strict_types=1);
require_once __DIR__.'/inventory.php';require_once APP_ROOT.'/includes/requisitions.php';require_once APP_ROOT.'/includes/counts.php';require_once APP_ROOT.'/includes/admin.php';
$f=inventory_fixture();$actor=$f['actor'];$reviewer=$f['reviewer'];$wire=$f['wire'];$a=$f['a'];
function act_as(int $actor): void {$_SESSION['user_id']=$actor;$_SESSION['credential_version']=hash('sha256',scalar('SELECT password_hash FROM users WHERE id=?',[$actor]));}
$input=['department_id'=>$f['department'],'warehouse_id'=>$a,'purpose'=>'Repair distribution line'];
$receive=inventory_draft($f,'RECEIPT',[inventory_line($wire,'12',null,$a)]);post_document($receive,$actor);
$request=create_request($input,[['material_id'=>$wire,'quantity'=>'10']],$actor,doc_number('REQ'));
request_transition($request,'submit',$actor,[]);$item=(int)scalar('SELECT id FROM requisition_items WHERE requisition_id=?',[$request]);$approval=['approved'=>[$item=>'8']];
expect_inventory_rejection(fn()=>request_transition($request,'approve',$actor,$approval),'different authorized','self approval rejected');
act_as($reviewer);expect_inventory_rejection(fn()=>request_transition($request,'approve',$reviewer,['approved'=>[$item=>'11']]),'exceeds','excess approval rejected');request_transition($request,'approve',$reviewer,$approval);
assert_balance($wire,$a,'12');act_as($actor);
$issue=fn($q)=>inventory_draft($f,'ISSUE',[inventory_line($wire,$q,$a,null,['requisition_item_id'=>$item])]);
$partial=$issue('3');post_document($partial,$actor);checked(scalar('SELECT status FROM requisitions WHERE id=?',[$request])==='PARTIALLY_ISSUED','partial fulfillment');
$excess=$issue('6');expect_inventory_rejection(fn()=>post_document($excess,$actor),'remaining','excess linked issue rejected');$last=$issue('5');post_document($last,$actor);
checked(scalar('SELECT status FROM requisitions WHERE id=?',[$request])==='ISSUED','request fully issued');
reverse_document($last,$actor,'Correct final quantity');checked(scalar('SELECT status FROM requisitions WHERE id=?',[$request])==='PARTIALLY_ISSUED','reversal restores outstanding approved quantity');
$rejected=create_request($input,[['material_id'=>$wire,'quantity'=>'1']],$actor,doc_number('REQ'));request_transition($rejected,'submit',$actor,[]);act_as($reviewer);request_transition($rejected,'reject',$reviewer,['remarks'=>'Already supplied']);checked(scalar('SELECT decision FROM requisition_approvals WHERE requisition_id=?',[$rejected])==='REJECTED','rejection recorded');
act_as($actor);$header=['warehouse_id'=>$a,'count_date'=>date('Y-m-d')];
function complete_count(array $f,array $header,string $target): int {
    $count=open_count($header,$f['actor'],doc_number('COUNT'));$values=[];
    foreach(rows('SELECT id,material_id,stock_condition,system_qty FROM stock_count_items WHERE stock_count_id=?',[$count]) as $i){
        $qty=$i['material_id']==$f['wire'] && $i['stock_condition']==='GOOD'?$target:$i['system_qty'];
        $values[$i['id']]=['quantity'=>$qty,'remarks'=>'Observed on count sheet'];
    }
    record_count_items($count,$f['actor'],$values);count_transition($count,'submit',$f['actor']);return $count;
}
$count=complete_count($f,$header,'7');assert_balance($wire,$a,'9');act_as($reviewer);count_transition($count,'approve',$reviewer);assert_balance($wire,$a,'7');
checked((bool)scalar("SELECT c.id FROM stock_counts c JOIN stock_documents d ON d.id=c.adjustment_document_id WHERE c.id=? AND c.status='POSTED' AND d.document_type='ADJUSTMENT' AND d.posted_by=?",[$count,$reviewer]),'reviewed count posts a linked adjustment');
act_as($actor);$stale=complete_count($f,$header,'7');$t=inventory_draft($f,'RECEIPT',[inventory_line($wire,'1',null,$a)]);post_document($t,$actor);reverse_document($t,$actor,'QA intervening movement');act_as($reviewer);
expect_inventory_rejection(fn()=>count_transition($stale,'approve',$reviewer),'snapshot','intervening movements detected despite unchanged balance');assert_reconciled($f);
$read=(int)scalar("SELECT COUNT(*) FROM notifications WHERE requisition_id=? AND type='APPROVED'",[$request]);checked($read===1,'approval delivered to requester without duplication');
$justRole=(int)scalar("SELECT id FROM roles WHERE code='REQUESTER'");act_as($actor);
db()->beginTransaction();try{
    $admin=(int)scalar("SELECT id FROM roles WHERE code='ADMIN'");run_sql('UPDATE users SET is_active=0 WHERE role_id=? AND id<>?',[$admin,$actor]);
    $adminInput=['username'=>'test-disable','role_id'=>$admin,'is_active'=>'0'];expect_inventory_rejection(fn()=>save_account($adminInput,$actor),'active administrator','last active administrator protected');
}finally{db()->rollBack();}
$unprivileged=insert_record('users',['username'=>'scope-'.$f['tag'],'role_id'=>$justRole,'password_hash'=>password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT)]);
act_as($unprivileged);checked(!can('users.manage') && !can('requisition.approve'),'requester cannot administer or approve');

