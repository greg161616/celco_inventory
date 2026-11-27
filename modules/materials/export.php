<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';
$template = ($_GET['template'] ?? '') === '1';
$actor = require_permission($template ? 'materials.manage' : 'inventory.view');
require_once APP_ROOT . '/includes/exports.php';
require_once APP_ROOT . '/includes/material_spreadsheet.php';

$columns = material_workbook_columns();
$batch = id_value($_GET['batch'] ?? null, false);
$sql = material_export_query();
if ($batch !== null) {
    ensure($batch <= 1000000, 'Choose a valid export batch.');
    $sql .= ' LIMIT 500 OFFSET ' . (($batch - 1) * 500);
}
try {
    $records = $template ? [] : run_sql($sql);
    download_table('xlsx', $template ? 'Material import template' : 'CELCO material catalog', $columns, $records,
        ['generated_by'=>$actor['username'], 'template'=>true]);
} catch (DomainException $error) {
    throw new InputError('The catalog exceeds the workbook size limit. Open Import Excel to download batches of 500 materials.', 0, $error);
}
