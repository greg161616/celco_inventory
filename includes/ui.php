<?php
declare(strict_types=1);
function module_nav(): array {
    $groups=[
      'Workspace'=>['dashboard'=>['Overview','grid-1x2','dashboard.view'],'notifications'=>['Notifications','bell','dashboard.view']],
      'Inventory'=>['materials'=>['Materials','boxes','inventory.view'],'stock-in'=>['Receiving','box-arrow-in-down','stock.receive'],'stock-out'=>['Issuance','box-arrow-up','stock.issue'],'requisitions'=>['Requisitions','clipboard-check',can('requisition.approve')?'requisition.approve':(can('stock.issue')?'stock.issue':'requisition.create')],'returns'=>['Returns','arrow-return-left','stock.return'],'transfers'=>['Transfers','arrow-left-right','stock.transfer'],'stock-counts'=>['Physical counts','clipboard-data',can('stock_count.approve')?'stock_count.approve':'stock_count.manage'],'adjustments'=>['Adjustments','sliders','stock.adjust'],'disposals'=>['Disposals','recycle','stock.dispose'],'serials'=>['Serial register','upc-scan','inventory.view']],
      'Directories'=>['categories'=>['Categories','tags','materials.manage'],'units'=>['Units','rulers','materials.manage'],'suppliers'=>['Suppliers','truck','materials.manage'],'warehouses'=>['Warehouses','building','inventory.view'],'departments'=>['Departments','diagram-3','materials.manage'],'employees'=>['Employees','people','materials.manage'],'projects'=>['Projects','briefcase','materials.manage']],
      'Management'=>['reports'=>['Reports','bar-chart','reports.view'],'users'=>['Users','person-gear','users.manage'],'roles'=>['Roles & permissions','shield-lock','users.manage'],'audit-logs'=>['Audit trail','journal-text','audit.view'],'settings'=>['Settings','gear','settings.manage']]
    ];
    $nav=[];foreach($groups as $group=>$items) foreach($items as $key=>[$label,$icon,$permission]) $nav[$key]=compact('group','label','icon','permission');
    return $nav;
}
function field(string $name,string $label,mixed $value='',string $type='text',array $options=[],bool $required=false): void {
    echo '<label class="field"><span>'.e($label).($required?' *':'').'</span>';
    $attr=' name="'.e($name).'" '.($required?'required':'');
    if($type==='select'){
        echo '<select class="form-select"'.$attr.'><option value="">Select…</option>';
        foreach($options as $key=>$text) echo '<option value="'.e($key).'"'.((string)$key===(string)$value?' selected':'').'>'.e($text).'</option>';
        echo '</select>';
    } elseif($type==='textarea') echo '<textarea class="form-control"'.$attr.'>'.e($value).'</textarea>';
    else echo '<input class="form-control" type="'.e($type).'"'.$attr.' value="'.e($value).'">';
    echo '</label>';
}
function options_for(string $table): array {
    $label=match($table){'employees'=>'full_name','users'=>'username','materials'=>"CONCAT(material_code,' · ',name)",'units'=>"CONCAT(code,' · ',name)",default=>'name'};
    $where=match($table){'projects'=>" WHERE status IN ('ACTIVE','PLANNED')",'roles','units'=>'',default=>' WHERE is_active=1'};
    return array_column(rows("SELECT id,$label label FROM $table$where ORDER BY label"),'label','id');
}
function table_html(array $columns,array $records,?callable $action=null): void {
    echo '<div class="table-wrap table-responsive"><table class="table"><thead><tr>';
    foreach($columns as $label) echo '<th scope="col">'.e($label).'</th>';
    if($action) echo '<th scope="col">Actions</th>';
    echo '</tr></thead><tbody>';
    foreach($records as $record){echo '<tr>';foreach($columns as $key=>$label) echo '<td>'.e($record[$key]??'—').'</td>';if($action){echo '<td>';$action($record);echo '</td>';}echo '</tr>';}
    if(!$records) echo '<tr><td colspan="'.(count($columns)+($action?1:0)).'"><div class="empty-state"><i class="bi bi-inbox"></i> No records found.</div></td></tr>';
    echo '</tbody></table></div>';
}
function page_link(string $path,string $label,string $class='btn btn-outline-primary'): void { echo '<a class="'.e($class).'" href="'.e(app_url($path)).'">'.e($label).'</a> '; }
function page_num(): int { return max(1,min(1000000,(int)($_GET['page']??1))); }
function page_limit(): string { return ' LIMIT 25 OFFSET '.((page_num()-1)*25); }
function pager(int $total): void {
    $pages=max(1,(int)ceil($total/25));$page=page_num();
    echo '<nav class="pagination-bar" aria-label="Pagination">';
    foreach([$page-1=>'Previous',$page+1=>'Next'] as $next=>$label) if($next>=1 && $next<=$pages) echo '<a class="btn btn-light" href="?'.e(http_build_query(array_merge($_GET,['page'=>$next]))).'">'.e($label).'</a> ';
    echo '<small>Page '.e($page).' of '.e($pages).' · '.e($total).' records</small></nav>';
}
function button_action(string $action,string $label,bool $confirm=false): void {
    echo '<button type="submit" class="btn btn-primary" name="action" value="'.e($action).'"'.($confirm?' data-confirm="'.e($label).'?"':'').'>'.e($label).'</button> ';
}
function print_button(): void {echo '<button type="button" class="btn btn-outline-primary no-print" data-print>Print</button>';}

function material_picker(string $name): void {
    echo '<label class="field"><span>Find material</span><input data-material-search class="form-control" placeholder="Code or name" autocomplete="off"></label>';
    $options=array_column(rows("SELECT id,CONCAT(material_code,' · ',name) label FROM materials WHERE is_active=1 ORDER BY name LIMIT 40"),'label','id');
    field($name,'Material','','select',$options,true);
}
