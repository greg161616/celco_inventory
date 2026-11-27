<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/app.php';
require_once APP_ROOT.'/includes/catalog.php';
require_permission(in_array($module,['materials','warehouses'],true)?'inventory.view':'materials.manage');
$fields=catalogs()[$module];$title=module_nav()[$module]['label'];$base="modules/$module/index.php";
$id=id_value($_GET['id']??null,false);$edit=isset($_GET['edit']);$manage=can('materials.manage');
if($edit && !$manage) deny();
post_action(function() use($module,$id,$base){
    require_permission('materials.manage');
    if(($_POST['action']??'')==='delete'){
        ensure($id!==null && !isset(catalogs()[$module]['is_active']),'Archive this record using its active status.');
        atomic(function()use($module,$id){run_sql("DELETE FROM $module WHERE id=?",[$id]);audit('DELETE',$module,$id);});
        return $base;
    }
    return $base.'?id='.catalog_save($module,$_POST,$id);
},$base.($id?'?id='.$id:'').($edit?($id?'&':'?').'edit=1':''));
$record=$id ? row("SELECT * FROM $module WHERE id=?",[$id]) : [];
ensure(!$id||(bool)$record,'Record not found.');
layout_start($title,$module);
if($edit){
    echo '<form method="post" class="panel">'.csrf_field().'<div class="form-grid">';
    foreach($fields as $key=>[$label,$type,$arg]){
        $options=match($type){'ref'=>options_for($arg),'bool'=>[1=>'Yes',0=>'No'],'enum'=>array_combine($arg,$arg),default=>[]};
        $value=$record[$key]??(in_array($type,['bool','qty'],true)?$arg:'');
        field($key,$label,$value,match($type){'ref','bool','enum'=>'select','memo'=>'textarea','email'=>'email','date'=>'date',default=>'text'},$options,($fields[$key][3]??true) && $type!=='code');
    }
    echo '</div>';button_action('save','Save record');echo '</form>';
} elseif($id) {
    page_link($base,'Back to list');if($manage)page_link($base.'?id='.$id.'&edit=1','Edit record');
    echo '<dl class="panel">';foreach($fields as $key=>[$label,$type,$arg]){
        $v=$record[$key];if($type==='ref' && $v) $v=scalar("SELECT name FROM $arg WHERE id=?",[$v]);if($type==='bool')$v=$v?'Yes':'No';
        echo '<dt>'.e($label).'</dt><dd>'.e($v??'—').'</dd>';
    } echo '</dl>';
    if($manage && !isset($fields['is_active'])){echo '<form method="post">'.csrf_field();button_action('delete','Delete unused record',true);echo '</form>';}
    if(in_array($module,['materials','warehouses'],true)){
        $key=$module==='materials'?'s.material_id':'s.warehouse_id';
        $join=' FROM inventory_stocks s JOIN warehouses w ON w.id=s.warehouse_id JOIN materials m ON m.id=s.material_id JOIN units u ON u.id=m.unit_id';
        echo '<h2>Stock balances</h2>';table_html(['name'=>'Material','warehouse'=>'Warehouse','stock_condition'=>'Condition','quantity'=>'Quantity','unit'=>'Unit'],rows("SELECT m.name,w.name warehouse,s.stock_condition,s.quantity,u.code unit $join WHERE $key=? ORDER BY m.name,w.name",[$id]));
        echo '<h2>Recent movements</h2>';table_html(['document_no'=>'Document','posted_at'=>'Posted','quantity_delta'=>'Change','unit'=>'Unit','balance_after'=>'Balance'],rows("SELECT d.document_no,v.posted_at,v.quantity_delta,v.balance_after,u.code unit $join JOIN stock_movements v ON v.stock_id=s.id JOIN stock_document_lines l ON l.id=v.document_line_id JOIN stock_documents d ON d.id=l.document_id WHERE $key=? ORDER BY v.id DESC LIMIT 50",[$id]));
    }
} else {
    if($manage)page_link($base.'?edit=1','Add record','btn btn-primary');
    if($module==='materials'){if($manage)page_link('modules/materials/import.php','Import Excel');page_link('modules/materials/export.php','Export Excel');}
    echo '<form method="get" class="panel form-grid">';field('q','Search',$_GET['q']??'');$where=[];$args=[];
    $q=string_value($_GET['q']??'',150,false);if($q){$terms=[];foreach($fields as $key=>$def)if(in_array($def[1],['code','text','email','memo'],true)){ $terms[]="t.$key LIKE ?";$args[]='%'.$q.'%'; }$where[]='('.implode(' OR ',$terms).')';}
    if(isset($fields['is_active'])){field('active','Status',$_GET['active']??'','select',[1=>'Active',0=>'Inactive']);if(in_array($_GET['active']??'',['0','1'],true)){$where[]='t.is_active=?';$args[]=$_GET['active'];}}
    if($module==='materials'){field('category','Category',$_GET['category']??'','select',options_for('categories'));if(!empty($_GET['category'])){$where[]='t.category_id=?';$args[]=id_value($_GET['category']);}}
    echo '<button class="btn btn-outline-primary">Filter</button></form>';
    $where=$where?' WHERE '.implode(' AND ',$where):'';$cols=[];$select=['t.id'];$joins='';
    foreach($fields as $key=>[$label,$type,$arg]){
        if($type==='memo') continue;
        $cols[$key]=$label;$expr="t.$key";
        if($type==='ref'){$joins.=" LEFT JOIN $arg x_$key ON x_$key.id=t.$key";$expr="x_$key.name";}
        if($type==='bool')$expr="IF(t.$key,'Yes','No')";
        $select[]="$expr AS $key";
    }
    table_html($cols,rows('SELECT '.implode(',',$select)." FROM $module t $joins $where ORDER BY t.id DESC".page_limit(),$args),fn($r)=>page_link($base.'?id='.$r['id'],'View'));
    pager((int)scalar("SELECT COUNT(*) FROM $module t $where",$args));
}
layout_end();

