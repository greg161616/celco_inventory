<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once APP_ROOT . '/includes/report_definitions.php';

$actor = require_permission('reports.view');
$definitions = report_definitions();
$slug = choice($_GET['report'] ?? 'inventory-summary', array_keys($definitions));
$report = $definitions[$slug];
require_permission($report['permission']);
$base = 'modules/reports/index.php';
$params = ['report' => $slug];
$where = [];
$bindings = [];
$selectors = [
    'material_id' => ['Material', 'materials', "CONCAT(material_code, ' · ', name)"],
    'category_id' => ['Category', 'categories', 'name'],
    'warehouse_id' => ['Warehouse', 'warehouses', 'name'],
    'department_id' => ['Department', 'departments', 'name'],
    'employee_id' => ['Employee', 'employees', 'full_name'],
    'project_id' => ['Project', 'projects', 'name'],
    'supplier_id' => ['Supplier', 'suppliers', 'name'],
];
$statuses = match ($slug) {
    'material-requisition','requisition-fulfillment' =>
        ['DRAFT','SUBMITTED','APPROVED','REJECTED','PARTIALLY_ISSUED','ISSUED','CANCELLED','CLOSED'],
    'physical-inventory' => ['DRAFT','COUNTING','REVIEWED','POSTED','CANCELLED'],
    'serial-tracking' => ['PENDING','IN_STOCK','ISSUED','DISPOSED'],
    default => ['DRAFT','POSTED','CANCELLED'],
};
$enums = ['status'=>['Status',$statuses],'stock_condition'=>['Condition',['GOOD','DAMAGED','DEFECTIVE','SCRAP']],
    'document_type'=>['Transaction type',['OPENING','RECEIPT','ISSUE','RETURN','TRANSFER','ADJUSTMENT','DISPOSAL','REVERSAL']]];
foreach ($report['filters'] as $key => $expression) {
    if ($key === 'date') {
        foreach (['from','to'] as $bound) {
            if (($_GET[$bound] ?? '') !== '') {
                $params[$bound] = date_value($_GET[$bound]);
                $where[] = $expression . ($bound === 'from' ? ' >= ?' : ' < DATE_ADD(?, INTERVAL 1 DAY)');
                $bindings[] = $params[$bound];
            }
        }
        if (isset($params['from'], $params['to'])) {
            ensure($params['to'] >= $params['from'], 'End date must be on or after the start date.');
        }
    } elseif (($_GET[$key] ?? '') !== '') {
        $params[$key] = isset($selectors[$key]) ? id_value($_GET[$key]) : choice($_GET[$key], $enums[$key][1]);
        $alternatives = [];
        foreach ((array) $expression as $column) {
            $alternatives[] = "$column = ?";
            $bindings[] = $params[$key];
        }
        $where[] = '(' . implode(' OR ', $alternatives) . ')';
    }
}
$sql = str_replace('/*FILTERS*/', $where ? ' AND ' . implode(' AND ', $where) : '', $report['sql']);
$ordered = $sql . ' ORDER BY ' . $report['order'];
$format = choice($_GET['format'] ?? 'html', ['html','print','csv','xlsx','pdf']);
$metadata = ['company'=>setting('company_name','Camotes Electric Cooperative, Inc.'),
    'generated_by'=>$actor['full_name'] ?: $actor['username'], 'generated_at'=>date('Y-m-d H:i T'),
    'note'=>$report['note'], 'footer'=>setting('report_footer','')];

// Page data and quantity totals refer to a single stock snapshot even during posting.
db()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
db()->beginTransaction();
try {
    $count = (int) scalar("SELECT COUNT(*) FROM ($sql) AS result", $bindings);
    $totals = [];
    if ($report['totals']) {
        $sums = array_map(static fn($key) => "SUM($key) AS $key", $report['totals']);
        $totals = rows('SELECT unit, ' . implode(', ', $sums) . " FROM ($sql) AS result GROUP BY unit ORDER BY unit", $bindings);
    }
    if (in_array($format, ['csv','xlsx','pdf'], true)) {
        require_once APP_ROOT . '/includes/exports.php';
        // Stream unbuffered rows into the export writer and release the read snapshot before download.
        db()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $records = (static function () use ($ordered, $bindings, $totals, $report): Generator {
            $statement = run_sql($ordered, $bindings);
            try {
                while ($record = $statement->fetch()) {
                    yield $record;
                }
            } finally {
                $statement->closeCursor();
                db()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
            db()->commit();
            foreach ($totals as $total) {
                $total[array_key_first($report['columns'])] = 'Total';
                yield $total;
            }
        })();
        download_table($format, $report['title'], $report['columns'], $records, $metadata);
    }
    if ($format === 'print') {
        ensure($count <= 5000, 'Narrow the filters below 5,001 rows for browser printing, or use CSV.');
    }
    $data = rows($ordered . ($format === 'html' ? page_limit() : ''), $bindings);
    db()->commit();
} catch (Throwable $error) {
    if (isset($records)) {
        unset($records);
    }
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    if ($error instanceof DomainException) {
        throw new InputError($error->getMessage(), 0, $error);
    }
    throw $error;
}

layout_start('Reports', 'reports');
echo '<div class="no-print"><form class="panel form-grid" method="get">';
$choices = [];
foreach ($definitions as $key => $definition) {
    if (can($definition['permission'])) {
        $choices[$key] = $definition['title'];
    }
}
field('report','Report',$slug,'select',$choices,true);
echo '<div><button class="btn btn-primary">Open report</button></div></form>';
if ($report['filters']) {
    echo '<form class="panel form-grid" method="get"><input type="hidden" name="report" value="' . e($slug) . '">';
    foreach ($report['filters'] as $key => $expression) {
        if ($key === 'date') {
            field('from','Start date',$params['from'] ?? '', 'date');
            field('to','End date',$params['to'] ?? '', 'date');
        } elseif (isset($selectors[$key])) {
            [$label,$table,$column] = $selectors[$key];
            // Historical reports include archived entities in the filters.
            $options = array_column(rows("SELECT id, $column AS label FROM $table ORDER BY label"), 'label', 'id');
            field($key, $label, $params[$key] ?? '', 'select', $options);
        } else {
            [$label,$values] = $enums[$key];
            field($key, $label, $params[$key] ?? '', 'select', array_combine($values, $values));
        }
    }
    echo '<div><button class="btn btn-primary">Apply filters</button> ';
    page_link($base . '?report=' . rawurlencode($slug), 'Clear filters');
    echo '</div></form>';
}
echo '</div><section class="panel"><p>' . e($metadata['company']) . '</p><h2>' . e($report['title']) . '</h2><p>Generated '
    . e($metadata['generated_at']) . ' by ' . e($metadata['generated_by']) . '</p>';
if ($report['note']) {
    echo '<p>' . e($report['note']) . '</p>';
}
echo '<div class="no-print">';
foreach (['pdf'=>'PDF','xlsx'=>'Excel','csv'=>'CSV'] as $type => $label) {
    page_link($base . '?' . http_build_query($params + ['format'=>$type]), $label);
}
if ($format === 'print') {
    print_button();
} else {
    page_link($base . '?' . http_build_query($params + ['format'=>'print']), 'Print view');
}
echo '</div></section>';
table_html($report['columns'], $data);
if ($format === 'html') {
    pager($count);
}
if ($totals) {
    echo '<h2>Totals by unit</h2>';
    $labels = ['unit'=>'Unit'] + array_intersect_key($report['columns'], array_flip($report['totals']));
    table_html($labels, $totals);
}
if ($metadata['footer'] !== '') {
    echo '<p>' . e($metadata['footer']) . '</p>';
}
layout_end();
