<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/app.php';require_once APP_ROOT.'/includes/counts.php';
$user=require_permission(module_nav()['stock-counts']['permission']);$actor=(int)$user['id'];$base='modules/stock-counts/index.php';$id=id_value($_GET['id']??null,false);
$c=$id?row('SELECT c.*,w.name warehouse,u.username counter FROM stock_counts c JOIN warehouses w ON w.id=c.warehouse_id JOIN users u ON u.id=c.counted_by WHERE c.id=?',[$id]):null;ensure(!$id||$c,'Count not found.');
post_action(function()use($id,$base,$actor){
    $action=$_POST['action']??'';
    if($action==='create'){require_permission('stock_count.manage');return $base.'?id='.open_count($_POST,$actor,submitted_number('COUNT',$_POST));}
    ensure((bool)$id,'Choose a counting session.');
    if($action==='save'){require_permission('stock_count.manage');record_count_items($id,$actor,$_POST['items']??[]);}
    else count_transition($id,$action,$actor);
    return $base.'?id='.$id.'&page='.page_num();
},$base.($id?'?id='.$id.'&page='.page_num():''));
layout_start('Physical inventory','stock-counts');
if($c){
    page_link($base,'Counting sessions');page_link($base.'?id='.$id.'&print=1','Full count sheet');print_button();echo '<section class="panel"><h2>'.e($c['count_no']).'</h2><p>'.e(implode(' · ',[$c['warehouse'],$c['count_date'],$c['counter'],$c['status']])).'</p><p>'.e($c['remarks']).'</p>';
    if($c['adjustment_document_id'])echo '<p>Adjustment '.e(scalar('SELECT document_no FROM stock_documents WHERE id=?',[$c['adjustment_document_id']])).'</p>';
    $form=$c['counted_by']==$actor && $c['status']==='COUNTING' && can('stock_count.manage') && !isset($_GET['print']);
    $lines=rows("SELECT i.*,m.name,m.is_serialized,u.code unit,(i.counted_qty-i.system_qty) variance,(SELECT GROUP_CONCAT(s.serial_number ORDER BY s.id SEPARATOR '\n') FROM material_serials s JOIN stock_count_item_serials x ON x.serial_id=s.id WHERE x.stock_count_item_id=i.id) serials FROM stock_count_items i JOIN materials m ON m.id=i.material_id JOIN units u ON u.id=m.unit_id WHERE stock_count_id=? ORDER BY m.name,i.stock_condition".(isset($_GET['print'])?'':page_limit()),[$id]);
    echo '</section>';if($form){echo '<form method="post" class="panel">'.csrf_field();foreach($lines as $line){$key='items['.$line['id'].']';echo '<fieldset><legend>'.e($line['name'].' · '.$line['stock_condition'].' · '.$line['system_qty'].' '.$line['unit']).'</legend><div class="form-grid">';field($key.'[quantity]','Counted quantity',$line['counted_qty']);if($line['is_serialized'])field($key.'[serials]','Observed serials',$line['serials'],'textarea');field($key.'[remarks]','Discrepancy explanation',$line['remarks']);echo '</div></fieldset>';}button_action('save','Save this page');echo '</form>';}
    else table_html(['name'=>'Material','stock_condition'=>'Condition','unit'=>'Unit','system_qty'=>'Snapshot','counted_qty'=>'Counted','variance'=>'Discrepancy','serials'=>'Observed serials','remarks'=>'Explanation'],$lines);
    if(!isset($_GET['print']))pager((int)scalar('SELECT COUNT(*) FROM stock_count_items WHERE stock_count_id=?',[$id]));
    echo '<form method="post" class="no-print">'.csrf_field();if($form)button_action('submit','Submit count for review',true);
    if($c['status']==='REVIEWED' && $c['counted_by']!=$actor && can('stock_count.approve'))button_action('approve','Approve and post discrepancies',true);
    if(in_array($c['status'],['COUNTING','REVIEWED'],true)&&($c['counted_by']==$actor || can('stock_count.approve')))button_action('cancel','Cancel counting session',true);echo '</form>';
}else{
    if(can('stock_count.manage')){echo '<form method="post" class="panel">'.csrf_field().submission_field('COUNT').'<div class="form-grid">';field('warehouse_id','Warehouse',setting('default_warehouse'),'select',options_for('warehouses'),true);field('count_date','Count date',date('Y-m-d'),'date',[],true);field('remarks','Remarks');echo '</div>';button_action('create','Start counting session');echo '</form>';}
    table_html(['count_no'=>'Count','warehouse'=>'Warehouse','count_date'=>'Count date','status'=>'Status'],rows('SELECT c.id,c.count_no,w.name warehouse,c.count_date,c.status FROM stock_counts c JOIN warehouses w ON w.id=c.warehouse_id ORDER BY c.id DESC'.page_limit()),fn($r)=>page_link($base.'?id='.$r['id'],'Open count'));pager((int)scalar('SELECT COUNT(*) FROM stock_counts'));
}layout_end();
