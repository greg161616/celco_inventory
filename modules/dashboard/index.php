<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/app.php';
require_permission('dashboard.view');require_once APP_ROOT.'/includes/dashboard.php';
$data=dashboard_data($_GET);layout_start('Inventory overview','dashboard');
echo '<form method="get" class="panel form-grid" data-dashboard-filter>';field('warehouse_id','Warehouse',$_GET['warehouse_id']??'','select',options_for('warehouses'));field('category_id','Category',$_GET['category_id']??'','select',options_for('categories'));field('from','From',$_GET['from']??date('Y-m-01',strtotime('-5 months')),'date');field('to','Through',$_GET['to']??date('Y-m-d'),'date');echo '<button class="btn btn-primary">Apply filters</button></form><div id="dashboard-results">';dashboard_panels($data);echo '</div>';layout_end();
