<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/app.php';require_once APP_ROOT.'/includes/inventory.php';require_once APP_ROOT.'/includes/notifications.php';
$types=['stock-in'=>['RECEIPT'],'stock-out'=>['ISSUE'],'returns'=>['RETURN'],'transfers'=>['TRANSFER'],'disposals'=>['DISPOSAL'],'adjustments'=>['ADJUSTMENT','OPENING']][$module];
$type=choice($_GET['type']??$types[0],$types);$user=require_permission(DOCUMENT_PERMISSIONS[$type]);$actor=(int)$user['id'];$base="modules/$module/index.php?type=$type";
$id=id_value($_GET['id']??null,false);$doc=$id?row('SELECT d.*,u.username creator,p.username poster FROM stock_documents d JOIN users u ON u.id=d.created_by LEFT JOIN users p ON p.id=d.posted_by WHERE d.id=? AND d.document_type=?',[$id,$type]):null;
ensure(!$id||$doc,'Document not found.');
post_action(function()use($base,$id,$doc,$actor,$type){
    $action=$_POST['action']??'';
    if($action==='edit'){
        ensure($doc && $doc['created_by']==$actor && $doc['status']==='DRAFT','Only the author may edit a draft.');
        save_document($type,$_POST,$_POST['lines']??[],$actor,$doc['document_no'],false,$id);return $base.'&id='.$id;
    }
    if($action==='create'){
        $new=save_document($type,$_POST,$_POST['lines']??[],$actor,submitted_number($type,$_POST));return $base.'&id='.$new;
    }
    ensure((bool)$doc,'Choose a document.');
    if($action==='post')post_document($id,$actor,$type);
    elseif($action==='reverse'){require_permission('stock.reverse');reverse_document($id,$actor,(string)($_POST['reason']??''),$type);}
    elseif($action==='cancel')atomic(function()use($id,$actor){ensure(run_sql("UPDATE stock_documents SET status='CANCELLED' WHERE id=? AND status='DRAFT' AND created_by=?",[$id,$actor])->rowCount()===1,'Only the author may cancel a draft.');audit('CANCEL','stock_documents',$id);});
    else throw new InputError('Unknown action.');
    return $base.'&id='.$id;
},$base.($id?'&id='.$id:(isset($_GET['new'])?'&new=1':'')));
layout_start(module_nav()[$module]['label'],$module);
$currencyLabel=currency_symbol(setting('currency','PHP'));
$editing=$doc && isset($_GET['edit']);
if($editing)ensure($doc['created_by']==$actor && $doc['status']==='DRAFT','Only the author may edit a draft.');
if($doc && !$editing){
    if($doc['status']==='DRAFT' && $doc['created_by']==$actor)page_link($base.'&id='.$id.'&edit=1','Edit draft');
    page_link($base,'History');print_button();
    echo '<article class="panel"><h2>'.e($doc['document_no']).'</h2><p>'.e($doc['status']).' · '.e($doc['document_date']).' · Created by '.e($doc['creator']).'</p><p>'.nl2br(e($doc['remarks'])).'</p>';
    $context=row('SELECT s.name Supplier,dep.name Department,e.full_name Employee,p.name Project FROM stock_documents d LEFT JOIN suppliers s ON s.id=d.supplier_id LEFT JOIN departments dep ON dep.id=d.department_id LEFT JOIN employees e ON e.id=d.recipient_employee_id LEFT JOIN projects p ON p.id=d.project_id WHERE d.id=?',[$id]);
    foreach(array_filter($context+['Reference'=>$doc['external_reference'],'Posted by'=>$doc['poster'],'Posted at'=>$doc['posted_at']]) as $k=>$v)echo '<p><b>'.e($k).'</b> '.e($v).'</p>';
    $lines=rows("SELECT l.id,m.material_code,m.name,l.quantity,u.code unit,l.unit_cost,CONCAT(w1.name,' / ',l.from_condition) source,CONCAT(w2.name,' / ',l.to_condition) destination,l.requisition_item_id,l.return_of_line_id,l.remarks,(SELECT GROUP_CONCAT(s.serial_number ORDER BY s.serial_number SEPARATOR ', ') FROM stock_document_line_serials x JOIN material_serials s ON s.id=x.serial_id WHERE x.document_line_id=l.id) serials FROM stock_document_lines l JOIN materials m ON m.id=l.material_id JOIN units u ON u.id=m.unit_id LEFT JOIN warehouses w1 ON w1.id=l.from_warehouse_id LEFT JOIN warehouses w2 ON w2.id=l.to_warehouse_id WHERE l.document_id=?",[$id]);
    table_html(['id'=>'Line','name'=>'Material','quantity'=>'Quantity','unit'=>'Unit','unit_cost'=>'Unit cost ('.$currencyLabel.')','source'=>'From','destination'=>'To','serials'=>'Serials','requisition_item_id'=>'Request line','return_of_line_id'=>'Issue line','remarks'=>'Remarks'],$lines);
    $reverse=row('SELECT document_no,posted_at,remarks FROM stock_documents WHERE reverses_document_id=?',[$id]);if($reverse)table_html(['document_no'=>'Reversal','posted_at'=>'Posted','remarks'=>'Reason'],[$reverse]);
    echo '</article><form method="post" class="no-print">'.csrf_field();
    if($doc['status']==='DRAFT'){button_action('post',$type==='DISPOSAL'?'Approve and post':'Post document',true);if($doc['created_by']==$actor)button_action('cancel','Cancel draft',true);}
    elseif($doc['status']==='POSTED' && !$reverse && can('stock.reverse')){field('reason','Reason for reversal');button_action('reverse','Reverse document',true);}
    echo '</form>';
} elseif(isset($_GET['new'])||$editing){
    page_link($base,'History');$wh=options_for('warehouses');$condition=array_combine(CONDITIONS,CONDITIONS);
    $prefill=[];
    if($editing){
        $prefill=rows("SELECT l.*,m.name material,IF(l.from_warehouse_id IS NULL,'IN','OUT') direction FROM stock_document_lines l JOIN materials m ON m.id=l.material_id WHERE l.document_id=? ORDER BY l.id",[$id]);
        $serials=rows('SELECT x.document_line_id,s.serial_number FROM stock_document_line_serials x JOIN material_serials s ON s.id=x.serial_id JOIN stock_document_lines l ON l.id=x.document_line_id WHERE l.document_id=? ORDER BY x.id',[$id]);
        $assigned=[];foreach($serials as $s)$assigned[$s['document_line_id']][]=$s['serial_number'];
        foreach($prefill as &$line)$line['serials']=implode("\n",$assigned[$line['id']]??[]);unset($line);
    }
    echo '<form method="post" class="panel" data-lines-prefill="'.e(json_encode($prefill)).'" data-lines-form data-request-id="'.e($type==='ISSUE'?($_GET['request']??''):'').'" data-request-url="'.e(app_url('api/request.php')).'" data-lookup="'.e(app_url('api/lookup.php')).'">'.csrf_field().($editing?'<input type="hidden" name="version" value="'.e(draft_version($id)).'">':submission_field($type)).'<h2>'.e(ucfirst(strtolower($type))).' details</h2><div class="form-grid">';
    field('document_date','Document date',$doc['document_date']??date('Y-m-d'),'date',[],true);field('external_reference','Delivery, PO or work reference',$doc['external_reference']??'');
    if($type==='RECEIPT')field('supplier_id','Supplier',$doc['supplier_id']??'','select',options_for('suppliers'));
    if(in_array($type,['ISSUE','RETURN'],true)){field('department_id','Department',$doc['department_id']??'','select',options_for('departments'),$type==='ISSUE');field('recipient_employee_id',$type==='ISSUE'?'Receiving employee':'Returning employee',$doc['recipient_employee_id']??'','select',options_for('employees'),true);field('project_id','Project / work order',$doc['project_id']??'','select',options_for('projects'));}
    field('remarks','Purpose / inspection remarks',$doc['remarks']??'','textarea',[],in_array($type,['ISSUE','RETURN','ADJUSTMENT','DISPOSAL'],true));echo '</div><h2>Material lines</h2><div data-lines><fieldset class="panel" data-line><legend>Material line</legend><div class="form-grid">';
    material_picker('lines[0][material_id]');field('lines[0][quantity]','Quantity','','text',[],true);
    if(in_array($type,['RECEIPT','OPENING'],true))field('lines[0][unit_cost]','Unit cost ('.$currencyLabel.')');
    if($type==='ADJUSTMENT')field('lines[0][direction]','Direction','','select',['IN'=>'Increase','OUT'=>'Decrease'],true);
    if(in_array($type,['ISSUE','TRANSFER','ADJUSTMENT','DISPOSAL'],true)){field('lines[0][from_warehouse_id]','Source warehouse',setting('default_warehouse'),'select',$wh);field('lines[0][from_condition]','Source condition',$type==='DISPOSAL'?'DAMAGED':'GOOD','select',$type==='ISSUE'?['GOOD'=>'GOOD']:$condition);}
    if(in_array($type,['RETURN','RECEIPT','OPENING','TRANSFER','ADJUSTMENT'],true)){field('lines[0][to_warehouse_id]','Destination warehouse',setting('default_warehouse'),'select',$wh);field('lines[0][to_condition]','Destination condition','GOOD','select',$condition);}
    if($type==='ISSUE'){
        $opts=array_column(rows("SELECT f.requisition_item_id,CONCAT(r.request_no,' · ',m.name,' · ',f.remaining_qty,' ',u.code,' · ',w.name) label FROM v_requisition_fulfillment f JOIN requisitions r ON r.id=f.requisition_id JOIN warehouses w ON w.id=r.warehouse_id JOIN materials m ON m.id=f.material_id JOIN units u ON u.id=m.unit_id WHERE r.status IN ('APPROVED','PARTIALLY_ISSUED') AND f.remaining_qty>0 ORDER BY r.id DESC"),'label','requisition_item_id');
        field('lines[0][requisition_item_id]','Approved request line (blank for direct issue)','','select',$opts);
    }
    if($type==='RETURN'){
        $opts=array_column(rows("SELECT l.id,CONCAT(d.document_no,' · ',m.name,' · ',l.quantity,' ',u.code) label FROM stock_document_lines l JOIN stock_documents d ON d.id=l.document_id JOIN materials m ON m.id=l.material_id JOIN units u ON u.id=m.unit_id WHERE d.document_type='ISSUE' AND d.status='POSTED' AND NOT EXISTS(SELECT 1 FROM stock_documents r WHERE r.reverses_document_id=d.id AND r.status='POSTED') ORDER BY d.id DESC"),'label','id');
        field('lines[0][return_of_line_id]','Original issue line','','select',$opts,true);
    }
    field('lines[0][serials]','Serial numbers (one per line)','','textarea');field('lines[0][remarks]','Line remarks');echo '</div><div data-stock-info aria-live="polite"></div><button type="button" class="btn btn-light" data-remove-line>Remove line</button></fieldset></div><button type="button" class="btn btn-outline-primary" data-add-line>Add material line</button> ';button_action($editing?'edit':'create','Save draft for review');echo '</form>';
} else {
    foreach($types as $t)page_link("modules/$module/index.php?type=$t&new=1",'New '.strtolower($t),'btn btn-primary');
    if($module==='disposals'){
        echo '<h2>Materials awaiting inspection or disposal</h2>';
        table_html(['material'=>'Material','warehouse'=>'Warehouse','stock_condition'=>'Condition','quantity'=>'On hand','unit'=>'Unit'],rows("SELECT m.name material,w.name warehouse,s.stock_condition,s.quantity,u.code unit FROM inventory_stocks s JOIN materials m ON m.id=s.material_id JOIN warehouses w ON w.id=s.warehouse_id JOIN units u ON u.id=m.unit_id WHERE s.stock_condition<>'GOOD' AND s.quantity>0 ORDER BY w.name,m.name LIMIT 100"));
    }
    if(count($types)>1)foreach($types as $t)page_link("modules/$module/index.php?type=$t",ucfirst(strtolower($t)).' history');
    echo '<form class="panel" method="get"><input type="hidden" name="type" value="'.e($type).'">';field('q','Find document or reference',$_GET['q']??'');echo '<button class="btn btn-light">Search</button></form>';
    $q='%'.string_value($_GET['q']??'',100,false).'%';$args=[$type,$q,$q];$where=' WHERE document_type=? AND (document_no LIKE ? OR external_reference LIKE ?)';
    table_html(['document_no'=>'Document','document_date'=>'Date','status'=>'Status','external_reference'=>'Reference'],rows("SELECT id,document_no,document_date,status,external_reference FROM stock_documents $where ORDER BY id DESC".page_limit(),$args),fn($r)=>page_link($base.'&id='.$r['id'],'Review'));
    pager((int)scalar("SELECT COUNT(*) FROM stock_documents $where",$args));
}
echo '<script defer src="'.e(app_url('assets/js/forms.js')).'"></script>';layout_end();
