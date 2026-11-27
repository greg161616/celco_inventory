<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/app.php';require_once APP_ROOT.'/includes/requisitions.php';
$user=require_permission(module_nav()['requisitions']['permission']);$actor=(int)$user['id'];$base='modules/requisitions/index.php';$id=id_value($_GET['id']??null,false);
$r=$id?request_access($id,$actor):null;
post_action(function()use($base,$id,$actor){
    $action=$_POST['action']??'';
    if($action==='create'){require_permission('requisition.create');return $base.'?id='.create_request($_POST,$_POST['lines']??[],$actor,submitted_number('REQ',$_POST));}
    ensure((bool)$id,'Select a request.');request_transition($id,$action,$actor,$_POST);return $base.'?id='.$id;
},$base.($id?'?id='.$id:''));
layout_start('Material requisitions','requisitions');
if($r){
    page_link($base,'All requests');print_button();echo '<section class="panel"><h2>'.e($r['request_no']).'</h2><p>'.e(implode(' · ',array_filter([$r['status'],$r['department'],$r['requester'],$r['warehouse'],$r['project']]))).'</p><p>'.e($r['purpose']).'</p></section>';
    $lines=request_lines($id);table_html(['material'=>'Material','unit'=>'Unit','available'=>'Available now','requested_qty'=>'Requested','approved_qty'=>'Approved','issued_qty'=>'Issued','remaining_qty'=>'Remaining'],$lines);
    echo '<form method="post" class="panel no-print">'.csrf_field();
    if($actor==$r['requested_by'] && can('requisition.create')){if($r['status']==='DRAFT')button_action('submit','Submit for review');if(in_array($r['status'],['DRAFT','SUBMITTED'],true))button_action('cancel','Cancel request',true);}
    if($actor!=$r['requested_by'] && can('requisition.approve') && $r['status']==='SUBMITTED'){
        foreach($lines as $l)field('approved['.$l['requisition_item_id'].']',$l['material'].' · approve ('.$l['unit'].')',$l['requested_qty']);
        field('remarks','Decision remarks','','textarea');button_action('approve','Approve quantities',true);button_action('reject','Reject request',true);
    }echo '</form>';
    if(can('stock.issue')&&in_array($r['status'],['APPROVED','PARTIALLY_ISSUED'],true))page_link('modules/stock-out/index.php?new=1&request='.$id,'Prepare issuance');
    echo '<h2>Decision history</h2>';table_html(['decided_at'=>'Time','username'=>'Reviewer','decision'=>'Decision','remarks'=>'Remarks'],rows('SELECT a.decided_at,u.username,a.decision,a.remarks FROM requisition_approvals a JOIN users u ON u.id=a.decided_by WHERE requisition_id=? ORDER BY a.id',[$id]));
} elseif(isset($_GET['new'])) {
    require_permission('requisition.create');echo '<form class="panel" method="post" data-lines-form data-lookup="'.e(app_url('api/lookup.php')).'">'.csrf_field().submission_field('REQ').'<p>Requester · '.e($user['username']).'</p><div class="form-grid">';
    field('department_id','Department',$user['department_id'],'select',options_for('departments'),true);field('warehouse_id','Warehouse',setting('default_warehouse'),'select',options_for('warehouses'),true);field('project_id','Project','','select',options_for('projects'));field('purpose','Purpose','','textarea',[],true);echo '</div><div data-lines><fieldset class="panel" data-line><legend>Requested material</legend><div class="form-grid">';
    material_picker('lines[0][material_id]');field('lines[0][quantity]','Requested quantity','','text',[],true);field('lines[0][remarks]','Remarks');echo '</div><div data-stock-info></div><button type="button" class="btn btn-light" data-remove-line>Remove line</button></fieldset></div><button type="button" class="btn btn-light" data-add-line>Add material</button> ';button_action('create','Save request');echo '</form><script defer src="'.e(app_url('assets/js/forms.js')).'"></script>';
} else {
    if(can('requisition.create'))page_link($base.'?new=1','New request','btn btn-primary');$all=can('requisition.approve')||can('stock.issue');$q='%'.string_value($_GET['q']??'',80,false).'%';$where=' WHERE request_no LIKE ?'.($all?'':' AND requested_by=?');$args=$all?[$q]:[$q,$actor];echo '<form class="panel">';field('q','Find request number',$_GET['q']??'');echo '<button class="btn btn-light">Search</button></form>';
    table_html(['request_no'=>'Request','requester'=>'Requester','created_at'=>'Created','status'=>'Status'],rows("SELECT r.id,request_no,u.username requester,r.created_at,status FROM requisitions r JOIN users u ON u.id=r.requested_by $where ORDER BY r.id DESC".page_limit(),$args),fn($x)=>page_link($base.'?id='.$x['id'],'View'));
    pager((int)scalar("SELECT COUNT(*) FROM requisitions $where",$args));
}layout_end();
