<?php
declare(strict_types=1);
function dashboard_data(array $input): array {
    $warehouse=id_value($input['warehouse_id']??null,false);$category=id_value($input['category_id']??null,false);
    $from=date_value($input['from']??date('Y-m-01',strtotime('-5 months')));$to=date_value($input['to']??date('Y-m-d'));
    ensure($from<=$to,'Start date must precede end date.');
    $catalog=$category?' AND m.category_id=?':'';$categoryArgs=$category?[$category]:[];
    $location=$warehouse?' AND s.warehouse_id=?':'';$locationArgs=$warehouse?[$warehouse]:[];
    $available="SELECT m.id,m.material_code,m.name,u.code unit,m.reorder_level,COALESCE(SUM(IF(s.stock_condition='GOOD',s.quantity,0)),0) available_qty FROM materials m JOIN units u ON u.id=m.unit_id LEFT JOIN inventory_stocks s ON s.material_id=m.id $location WHERE m.is_active=1 $catalog GROUP BY m.id,m.material_code,m.name,u.code,m.reorder_level";
    $stockArgs=[...$locationArgs,...$categoryArgs];
    $movement=" FROM stock_movements v JOIN inventory_stocks s ON s.id=v.stock_id JOIN materials m ON m.id=s.material_id JOIN units u ON u.id=m.unit_id JOIN warehouses w ON w.id=s.warehouse_id JOIN stock_document_lines l ON l.id=v.document_line_id JOIN stock_documents d ON d.id=l.document_id WHERE d.document_date BETWEEN ? AND ? $location $catalog";
    $moveArgs=[$from,$to,...$stockArgs];
    $scope=can('requisition.approve')||can('stock.issue')||can('audit.view')?'':' AND r.requested_by='.(int)current_user()['id'];
    if($warehouse)$scope.=' AND r.warehouse_id='.(int)$warehouse;
    if($category)$scope.=' AND EXISTS (SELECT 1 FROM requisition_items ri JOIN materials m ON m.id=ri.material_id WHERE ri.requisition_id=r.id AND m.category_id='.(int)$category.')';
    $req=" FROM requisitions r WHERE 1=1 $scope";
    $metrics=[
      'Active materials'=>(int)scalar("SELECT COUNT(*) FROM materials m WHERE m.is_active=1 $catalog",$categoryArgs),
      'Categories'=>(int)scalar('SELECT COUNT(*) FROM categories WHERE is_active=1'),
      'Warehouses'=>(int)scalar('SELECT COUNT(*) FROM warehouses WHERE is_active=1'),
      'Low stock'=>(int)scalar("SELECT COUNT(*) FROM ($available) a WHERE available_qty<=reorder_level",$stockArgs),
      'Out of stock'=>(int)scalar("SELECT COUNT(*) FROM ($available) a WHERE available_qty=0",$stockArgs),
      'Pending requests'=>(int)scalar("SELECT COUNT(*) $req AND status='SUBMITTED'"),
      'Awaiting issuance'=>(int)scalar("SELECT COUNT(*) $req AND status IN ('APPROVED','PARTIALLY_ISSUED')"),
      'Inventory valuation'=>'Unavailable'
    ];
    $charts=[
      'Monthly receipts and issues'=>rows("SELECT DATE_FORMAT(d.document_date,'%Y-%m') label,COUNT(DISTINCT IF(d.document_type='RECEIPT',d.id,NULL)) Receipts,COUNT(DISTINCT IF(d.document_type='ISSUE',d.id,NULL)) Issues $movement GROUP BY label ORDER BY label",$moveArgs),
      'Materials by category'=>rows("SELECT c.name label,COUNT(m.id) Materials FROM categories c JOIN materials m ON m.category_id=c.id WHERE m.is_active=1 $catalog GROUP BY c.id,c.name ORDER BY Materials DESC",$categoryArgs),
      'Requisition status'=>rows("SELECT r.status label,COUNT(*) Requests $req GROUP BY r.status"),
      'Warehouse inventory'=>rows("SELECT w.name label,COUNT(DISTINCT m.id) Materials FROM inventory_stocks s JOIN warehouses w ON w.id=s.warehouse_id JOIN materials m ON m.id=s.material_id WHERE s.quantity>0 $location $catalog GROUP BY w.id,w.name",$stockArgs),
      'Frequently issued materials'=>rows("SELECT CONCAT(m.material_code,' · ',m.name) label,COUNT(DISTINCT d.id) Issues $movement AND d.document_type='ISSUE' GROUP BY m.id,m.material_code,m.name ORDER BY Issues DESC LIMIT 8",$moveArgs),
      'Monthly transaction activity'=>rows("SELECT DATE_FORMAT(d.document_date,'%Y-%m') label,COUNT(DISTINCT d.id) Documents $movement GROUP BY label ORDER BY label",$moveArgs)
    ];
    $today=rows("SELECT m.name Material,u.code Unit,d.document_type Type,SUM(l.quantity) Quantity $movement AND d.document_type IN ('RECEIPT','ISSUE') GROUP BY m.id,m.name,u.code,d.document_type",[date('Y-m-d'),date('Y-m-d'),...$stockArgs]);
    $tables=[
      'Recent movements'=>rows("SELECT d.document_no Document,m.name Material,w.name Warehouse,v.quantity_delta Quantity,u.code Unit,v.posted_at Posted $movement ORDER BY v.id DESC LIMIT 10",$moveArgs),
      'Low-stock warnings'=>rows("SELECT name Material,unit Unit,available_qty Available,reorder_level Minimum FROM ($available) a WHERE available_qty<=reorder_level ORDER BY available_qty,name LIMIT 12",$stockArgs),
      'Pending requests'=>rows("SELECT r.request_no Request,r.status Status,r.created_at Created $req AND r.status='SUBMITTED' ORDER BY r.id DESC LIMIT 8"),
      'Available stock'=>rows("SELECT name Material,unit Unit,available_qty Available FROM ($available) a ORDER BY name LIMIT 15",$stockArgs),
      'Materials received and issued today'=>$today,
      'Damaged and defective stock'=>rows("SELECT m.name Material,u.code Unit,s.stock_condition `Condition`,SUM(s.quantity) Quantity FROM inventory_stocks s JOIN materials m ON m.id=s.material_id JOIN units u ON u.id=m.unit_id WHERE s.stock_condition IN ('DAMAGED','DEFECTIVE','SCRAP') AND s.quantity>0 $location $catalog GROUP BY m.id,m.name,u.code,s.stock_condition ORDER BY m.name LIMIT 20",$stockArgs)
    ];
    if(can('audit.view')) $tables['Recent user activities']=rows('SELECT a.created_at Time,u.username User,a.action Activity,a.entity_type Entity FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 8');
    return compact('metrics','charts','tables');
}
function dashboard_panels(array $data): void {
    echo '<div class="stat-grid">';
    foreach($data['metrics'] as $label=>$value) echo '<article class="stat-card"><span>'.e($label).'</span><strong>'.e($value).'</strong></article>';
    echo '</div><div class="dashboard-grid">';
    foreach($data['charts'] as $title=>$records){
        echo '<section class="panel"><h2>'.e($title).'</h2>';
        if($records){$datasets=[];foreach(array_keys($records[0]) as $k)if($k!=='label')$datasets[]=['label'=>$k,'data'=>array_map('intval',array_column($records,$k))];
            $config=['type'=>'bar','data'=>['labels'=>array_column($records,'label'),'datasets'=>$datasets]];
            echo '<div class="chart-box"><canvas role="img" aria-label="'.e($title).'" data-chart="'.e(json_encode($config)).'"></canvas></div>';
        }else echo '<div class="empty-state"><i class="bi bi-bar-chart"></i> No activity in this selection.</div>';
        echo '</section>';
    }echo '</div>';
    foreach($data['tables'] as $title=>$records){echo '<section class="panel"><h2>'.e($title).'</h2>';if($records)table_html(array_combine(array_keys($records[0]),array_keys($records[0])),$records);else echo '<p class="empty-state">No records found.</p>';echo '</section>';}
}

