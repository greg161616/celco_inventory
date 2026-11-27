<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/app.php';
$actor = require_permission('materials.manage');
require_once APP_ROOT . '/includes/catalog.php';
require_once APP_ROOT . '/includes/material_spreadsheet.php';

$base = 'modules/materials/import.php';
$columns = material_workbook_columns();
$errorMessage = '';
if (isset($_SESSION['material_import']) && ($_SESSION['material_import']['expires'] < time()
    || $_SESSION['material_import']['actor'] !== (int) $actor['id'])) {
    unset($_SESSION['material_import']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = choice($_POST['action'] ?? '', ['preview','confirm','discard']);
        if ($action === 'preview') {
            unset($_SESSION['material_import']);
            $mode = choice($_POST['mode'] ?? 'create', ['create','merge']);
            $required = $mode === 'merge' ? array_keys($columns) : ['name','category','unit'];
            $input = read_material_upload($_FILES['workbook'] ?? [], $columns, $required);
            foreach ($input as &$record) {
                if (($record['material_code'] ?? '') === '') $record['material_code'] = doc_number('MAT');
            }
            unset($record);
            $plan = material_import_plan($input, $mode);
            $summary = [];
            foreach ($plan as $line => $entry) {
                $summary[$line] = array_intersect_key($entry, array_flip(['action','error','version']));
            }
            $_SESSION['material_import'] = ['actor'=>(int) $actor['id'],'mode'=>$mode,'input'=>$input,
                'summary'=>$summary,'token'=>bin2hex(random_bytes(24)),'expires'=>time()+1200];
            redirect_to($base);
        }
        $draft = $_SESSION['material_import'] ?? null;
        ensure(is_array($draft) && is_string($_POST['preview_token'] ?? null)
            && hash_equals($draft['token'], $_POST['preview_token']), 'The preview expired. Upload the workbook again.');
        if ($action === 'confirm') {
            $expected = [];
            foreach ($draft['summary'] as $line => $entry) {
                ensure($entry['error'] === '', 'Correct the workbook and upload it again.');
                $expected[$line] = $entry['version'];
            }
            $counts = commit_material_import($draft['input'], $draft['mode'], $expected);
            $_SESSION['flash'] = ['success', sprintf('Material import finished. Created %d, updated %d, unchanged %d.',
                $counts['Create'],$counts['Update'],$counts['Unchanged'])];
        }
        unset($_SESSION['material_import']);
        redirect_to($action === 'confirm' ? 'modules/materials/index.php' : $base);
    } catch (Throwable $error) {
        $errorMessage = $error instanceof DomainException ? $error->getMessage() : friendly_error($error);
    }
}
layout_start('Import materials', 'materials');
if ($errorMessage !== '') {
    echo '<div class="alert alert-danger" role="alert">' . e($errorMessage) . '</div>';
}
$draft = $_SESSION['material_import'] ?? null;
if ($draft) {
    $rows = [];
    $valid = true;
    foreach ($draft['input'] as $line => $record) {
        $entry = $draft['summary'][$line];
        $valid = $valid && $entry['error'] === '';
        $rows[] = ['line'=>$line,'action'=>$entry['action'],'error'=>$entry['error']] + $record;
    }
    echo '<section class="panel"><h2>Workbook preview</h2><p>' . count($rows) . ' rows · '
        . e($draft['mode'] === 'merge' ? 'Update matching codes and add new materials' : 'Add new materials') . '</p>';
    table_html(['line'=>'Row','action'=>'Action','error'=>'Validation'] + $columns, $rows);
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="preview_token" value="' . e($draft['token']) . '">';
    if ($valid) button_action('confirm', 'Confirm import', true);
    button_action('discard', 'Discard preview');
    echo '</form></section>';
}
echo '<form method="post" class="panel" enctype="multipart/form-data">' . csrf_field() . '<h2>Upload workbook</h2>
    <p>Use one worksheet with up to 500 material rows and a file size below 2 MB. Enter category names and unit codes from the CELCO directories. For serial tracking and active status, enter 1 for yes or 0 for no. Blank codes receive generated codes at preview.</p>';
page_link('modules/materials/export.php?template=1','Download blank template');
echo '<div class="form-grid"><label class="field"><span>Excel workbook</span><input class="form-control" type="file" name="workbook" accept=".xlsx" required></label>';
field('mode','Matching material codes','create','select',['create'=>'Reject existing codes','merge'=>'Update existing materials and add new ones'],true);
echo '</div>';
button_action('preview','Preview workbook');
echo '</form>';
$total = (int) scalar('SELECT COUNT(*) FROM materials');
if ($total > 500 && can('inventory.view')) {
    echo '<form method="get" action="' . e(app_url('modules/materials/export.php')) . '" class="panel">
        <label class="field"><span>Catalog batch (500 rows each)</span><input type="number" class="form-control" name="batch"
        value="1" min="1" max="' . (int) ceil($total/500) . '" required></label><button class="btn btn-outline-primary">Export batch</button></form>';
}
layout_end();
