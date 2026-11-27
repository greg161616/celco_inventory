<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function read_material_upload(array $file, array $columns, array $required = []): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
        !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        throw new DomainException('Choose an XLSX workbook under 2 MB and upload it again.');
    }
    if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
        throw new DomainException('Choose an Excel .xlsx workbook.');
    }
    return read_material_workbook($file['tmp_name'], $columns, $required);
}

/** Inspect ZIP contents before the spreadsheet reader decompresses XML and shared strings. */
function validate_material_workbook_archive(string $path): array
{
    if (!is_file($path) || filesize($path) > 2 * 1024 * 1024) {
        throw new DomainException('The material workbook must be under 2 MB.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new DomainException('The uploaded file is not a readable XLSX workbook.');
    }
    try {
        if ($zip->numFiles > 200 || $zip->locateName('xl/workbook.xml') === false) {
            throw new DomainException('Use a simple XLSX workbook containing one worksheet.');
        }
        $size = 0;
        $seen = [];
        $numbers = [];
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $entry = $zip->statIndex($index);
            $name = strtolower($entry['name']);
            $size += $entry['size'];
            if ($size > 8 * 1024 * 1024 || isset($seen[$name]) || str_contains($name, '..') ||
                str_contains($name, '\\') || str_starts_with($name, '/') ||
                preg_match('~(^|/)(externallinks|embeddings|vbaproject\.bin|activex)(/|$)~', $name)) {
                throw new DomainException('Use a workbook under 8 MB expanded, without macros or embedded content.');
            }
            $seen[$name] = true;
            if (preg_match('~^xl/worksheets/[^/]+\.xml$~', $name)) {
                $xml = $zip->getFromIndex($index);
                if ($xml === false || stripos($xml, '<!DOCTYPE') !== false) {
                    throw new DomainException('The worksheet XML is invalid.');
                }
                $reader = XMLReader::XML($xml, null, LIBXML_NONET);
                if (!$reader) throw new DomainException('The worksheet XML is invalid.');
                $coordinate = null;
                $cells = 0;
                while ($reader->read()) {
                    if ($reader->nodeType !== XMLReader::ELEMENT) continue;
                    if ($reader->localName === 'c') {
                        if (++$cells > 12500) throw new DomainException('The worksheet contains too many cells.');
                        $coordinate = in_array($reader->getAttribute('t'), [null,'n'], true) ? $reader->getAttribute('r') : null;
                    } elseif ($reader->localName === 'v' && $coordinate !== null) {
                        // PHP's numeric-cell reader can round large exact decimals to its float precision.
                        $numbers[$coordinate] = trim($reader->readString());
                        $coordinate = null;
                    }
                }
                $reader->close();
            }
            if (str_ends_with($name, '.rels')) {
                $xml = $zip->getFromIndex($index);
                if ($xml === false || stripos($xml, '<!DOCTYPE') !== false) {
                    throw new DomainException('Invalid workbook relationships.');
                }
                $doc = new DOMDocument();
                if (!@$doc->loadXML($xml, LIBXML_NONET) || $doc->doctype !== null) {
                    throw new DomainException('Invalid workbook relationships.');
                }
                foreach ($doc->getElementsByTagNameNS('*', 'Relationship') as $relationship) {
                    $target = trim($relationship->getAttribute('Target'));
                    if (strcasecmp($relationship->getAttribute('TargetMode'), 'External') === 0 ||
                        preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|\\\\)~i', $target)) {
                        throw new DomainException('Remove external workbook links before importing.');
                    }
                }
            }
        }
        return $numbers;
    } finally {
        $zip->close();
    }
}

/** Read local, trusted paths or HTTP upload temp files validated by read_material_upload(). */
function read_material_workbook(string $path, array $columns, array $required = []): array
{
    $numbers = validate_material_workbook_archive($path);
    $reader = new Xlsx();
    $reader->setReadDataOnly(true);
    $reader->setReadEmptyCells(false);
    try {
        $info = $reader->listWorksheetInfo($path);
        if (count($info) !== 1 || $info[0]['totalRows'] > 501 || $info[0]['totalColumns'] > count($columns)) {
            throw new DomainException('Use one worksheet, up to 500 material rows and only the listed columns.');
        }
        $workbook = $reader->load($path);
    } catch (DomainException $error) {
        throw $error;
    } catch (Throwable $error) {
        throw new DomainException('The XLSX workbook could not be opened.', 0, $error);
    }
    try {
        $sheet = $workbook->getSheet(0);
        $normalize = static fn(string $s): string => mb_strtolower(trim($s));
        $aliases = [];
        foreach ($columns as $key => $label) {
            $aliases[$normalize($label)] = $key;
            $aliases[$normalize($key)] = $key;
        }
        $mapping = [];
        $result = [];
        $lastRow = $sheet->getHighestDataRow();
        $width = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        if ($lastRow > 501 || $width > count($columns)) {
            throw new DomainException('The worksheet exceeds the supported row or column limit.');
        }
        for ($line = 1; $line <= $lastRow; ++$line) {
            $row = [];
            for ($col = 1; $col <= $width; ++$col) {
                $coordinate = Coordinate::stringFromColumnIndex($col) . $line;
                $cell = $sheet->getCell($coordinate);
                if (in_array($cell->getDataType(), [DataType::TYPE_FORMULA, DataType::TYPE_ERROR], true)) {
                    throw new DomainException("Cell {$coordinate} contains a formula or spreadsheet error. Paste values before importing.");
                }
                $raw = $cell->getValue();
                if ($raw !== null && $cell->getDataType() === DataType::TYPE_NUMERIC) {
                    if (!array_key_exists($coordinate, $numbers)) {
                        throw new DomainException("Format cell {$coordinate} as text to preserve its exact value.");
                    }
                    $raw = $numbers[$coordinate];
                }
                $value = trim($raw instanceof RichText ? $raw->getPlainText() : (string) $raw);
                if (mb_strlen($value) > 6000 || str_contains($value, "\0")) {
                    throw new DomainException("Cell {$coordinate} contains invalid or excessively long text.");
                }
                if ($line === 1) {
                    $key = $aliases[$normalize($value)] ?? null;
                    if ($key === null || in_array($key, $mapping, true)) {
                        throw new DomainException("Column {$coordinate} needs a unique, recognized material heading.");
                    }
                    $mapping[$col] = $key;
                } else {
                    $row[$mapping[$col]] = $value;
                }
            }
            if ($line === 1 && array_diff($required, $mapping)) {
                throw new DomainException('The workbook is missing required columns: ' . implode(', ', array_diff($required, $mapping)) . '.');
            }
            if ($line !== 1 && array_filter($row, static fn($v) => $v !== '')) {
                $result[$line] = $row;
            }
        }
        if (!$result) {
            throw new DomainException('The worksheet contains no material rows.');
        }
        return $result;
    } finally {
        $workbook->disconnectWorksheets();
    }
}

function material_workbook_columns(): array
{
    return [
        'material_code'=>'Material code', 'name'=>'Material name', 'specification'=>'Specifications',
        'category'=>'Category', 'unit'=>'Unit code', 'reorder_level'=>'Minimum stock',
        'is_serialized'=>'Serial tracking', 'is_active'=>'Active',
    ];
}

function material_export_query(): string
{
    return 'SELECT m.material_code,m.name,m.specification,c.name AS category,u.code AS unit,
        m.reorder_level,m.is_serialized,m.is_active FROM materials m JOIN categories c ON c.id=m.category_id
        JOIN units u ON u.id=m.unit_id ORDER BY m.id';
}

/** Query master data in batches and compare codes using the catalog's actual unique-key collation. */
function material_import_plan(array $input, string $mode): array
{
    choice($mode, ['create','merge']);
    $preview = [];
    $seen = [];
    $fields = array_keys(catalogs()['materials']);
    foreach (array_chunk($input, 100, true) as $chunk) {
        $selects = [];
        $bindings = [];
        foreach ($chunk as $line => $record) {
            $selects[] = 'SELECT ? AS line_no, CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci AS code,
                CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci AS category, CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci AS unit';
            array_push($bindings, $line, $record['material_code'] ?? '', $record['category'] ?? '', $record['unit'] ?? '');
        }
        $oldFields = implode(', ', array_map(static fn($key) => "m.$key AS old_$key", $fields));
        $used = implode(' OR ', array_map(static fn($table) =>
            "EXISTS(SELECT 1 FROM $table used_row WHERE used_row.material_id=m.id)", [
            'inventory_stocks','material_serials','stock_document_lines','stock_count_items','requisition_items',
        ]));
        $references = rows("SELECT sheet.line_no, HEX(WEIGHT_STRING(sheet.code)) AS code_key,
            c.id AS category_id, c.is_active AS active_category, u.id AS unit_id, m.id AS existing_id, $oldFields,
            ($used) AS material_used FROM (" . implode(' UNION ALL ', $selects) . ') sheet
            LEFT JOIN categories c ON c.name=sheet.category LEFT JOIN units u ON u.code=sheet.unit
            LEFT JOIN materials m ON m.material_code=sheet.code ORDER BY CAST(sheet.line_no AS UNSIGNED)', $bindings);
        foreach ($references as $ref) {
            $line = (int) $ref['line_no'];
            $record = $input[$line];
            $old = [];
            foreach ($fields as $key) {
                $old[$key] = $ref['old_' . $key];
            }
            $entry = ['id'=>(int) $ref['existing_id'], 'category_id'=>(int) $ref['category_id'],
                'unit_id'=>(int) $ref['unit_id'], 'error'=>'', 'data'=>[], 'action'=>''];
            $entry['version'] = hash('sha256', serialize([$entry['id'],$old,$entry['category_id'],$ref['active_category'],$entry['unit_id']]));
            try {
                ensure(!isset($seen[$ref['code_key']]), 'Material code is repeated in this workbook.');
                $seen[$ref['code_key']] = true;
                ensure($mode === 'merge' || !$entry['id'], 'Material code already exists.');
                ensure($entry['category_id'] > 0, 'Choose an existing category by name.');
                ensure($entry['unit_id'] > 0, 'Choose an existing unit code.');
                $data = [
                    'material_code'=>string_value($record['material_code'] ?? '',50),
                    'name'=>string_value($record['name'] ?? '',150),
                    'specification'=>string_value($record['specification'] ?? '',6000,false),
                    'category_id'=>$entry['category_id'], 'unit_id'=>$entry['unit_id'],
                    'reorder_level'=>decimal_value(($record['reorder_level'] ?? '') === '' ? '0' : $record['reorder_level']),
                    'is_serialized'=>(int) choice((string) (($record['is_serialized'] ?? '') === '' ? '0' : $record['is_serialized']), ['0','1']),
                    'is_active'=>(int) choice((string) (($record['is_active'] ?? '') === '' ? '1' : $record['is_active']), ['0','1']),
                ];
                $changed = false;
                foreach ($data as $key => $value) {
                    if ((string) $value !== (string) $old[$key]) $changed = true;
                }
                $entry['action'] = $entry['id'] ? ($changed ? 'Update' : 'Unchanged') : 'Create';
                if ($entry['action'] !== 'Unchanged') {
                    ensure((bool) $ref['active_category'], 'An active category is required to save this material.');
                    ensure(!$ref['material_used'] || ($old['unit_id'] == $data['unit_id'] && $old['is_serialized'] == $data['is_serialized']),
                        'Unit and serial tracking are locked after inventory use.');
                }
                $entry['data'] = $data;
            } catch (InputError $error) {
                $entry['action'] = 'Review';
                $entry['error'] = $error->getMessage();
            }
            $preview[$line] = $entry;
        }
    }
    ksort($preview, SORT_NUMERIC);
    return $preview;
}

/** Rebuild the preview under row locks and perform the entire import in one transaction. */
function commit_material_import(array $input, string $mode, array $expected): array
{
    return atomic(function () use ($input, $mode, $expected) {
        $prior = material_import_plan($input, $mode);
        foreach (['categories'=>'category_id','units'=>'unit_id','materials'=>'id'] as $table => $key) {
            lock_rows($table, array_column($prior, $key));
        }
        $locked = material_import_plan($input, $mode);
        $counts = ['Create'=>0, 'Update'=>0, 'Unchanged'=>0];
        foreach ($locked as $line => $item) {
            ensure($item['error'] === '', "Row $line: " . $item['error']);
            ensure(($expected[$line] ?? '') === $item['version'], "Row $line changed after preview. Upload the workbook again.");
            if ($item['action'] !== 'Unchanged') {
                catalog_save('materials', $item['data'], $item['id'] ?: null);
            }
            ++$counts[$item['action']];
        }
        audit('IMPORT','materials',null,['rows'=>count($input),'changes'=>$counts]);
        return $counts;
    });
}
