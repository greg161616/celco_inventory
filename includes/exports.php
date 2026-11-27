<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/** Preserve exact database decimals and material codes, including leading zeroes. */
function report_values(array $row, array $columns): array
{
    $values = [];
    foreach ($columns as $key => $heading) {
        $values[] = (string) ($row[$key] ?? '');
    }
    return $values;
}

function write_report_csv($stream, array $columns, iterable $rows, array $preamble = []): void
{
    fwrite($stream, "\xEF\xBB\xBF");
    $write = static function (array $values) use ($stream): void {
        foreach ($values as &$value) {
            // A quoted CSV field can still be evaluated by spreadsheet applications.
            if (preg_match('/^[\pZ\pC]*[=+@-]|^[\t\r\n]/u', $value)) {
                if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
                    $value = "'" . $value;
                }
            }
        }
        unset($value);
        if (fputcsv($stream, $values, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('Unable to write the CSV file.');
        }
    };
    foreach ($preamble as $line) {
        $write([$line]);
    }
    $write(array_values($columns));
    foreach ($rows as $row) {
        $write(report_values($row, $columns));
    }
}

function write_report_workbook($stream, array $columns, iterable $rows, array $preamble = []): void
{
    $workbook = new Spreadsheet();
    try {
        $sheet = $workbook->getActiveSheet()->setTitle('Inventory');
        $line = 1;
        foreach ($preamble as $caption) {
            $sheet->setCellValueExplicit('A' . $line++, $caption, DataType::TYPE_STRING);
        }
        $header = $line;
        $end = Coordinate::stringFromColumnIndex(count($columns));
        foreach (array_values($columns) as $index => $label) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValueExplicit($letter . $header, $label, DataType::TYPE_STRING);
            $sheet->getColumnDimension($letter)->setWidth(min(46, max(20, mb_strlen($label) + 4)));
        }
        $sheet->getStyle("A{$header}:{$end}{$header}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A{$header}:{$end}{$header}")->getFill()
            ->setFillType('solid')->getStartColor()->setARGB('FF003366');
        $sheet->freezePane('A' . ($header + 1));
        $cellCount = 0;
        foreach ($rows as $row) {
            $cellCount += count($columns);
            if ($cellCount > 45000) {
                throw new DomainException('Use a narrower date range or select CSV to export this many cells.');
            }
            ++$line;
            foreach (report_values($row, $columns) as $index => $value) {
                $sheet->setCellValueExplicit([$index + 1, $line], $value, DataType::TYPE_STRING);
            }
        }
        $sheet->setAutoFilter("A{$header}:{$end}{$line}");
        (new Xlsx($workbook))->save($stream);
    } finally {
        $workbook->disconnectWorksheets();
    }
}

function write_report_pdf($stream, array $columns, iterable $rows, array $preamble, string $footer): void
{
    $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<html><head><meta charset="UTF-8"><style>@page{margin:32pt 28pt 45pt}
        body{font:9pt "DejaVu Sans",sans-serif;color:#1e293b}h1{font-size:15pt;color:#003366}
        p{margin:2pt 0}table{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:12pt}
        th,td{padding:4pt;text-align:left;border-bottom:1px solid #dae3ed;overflow-wrap:break-word}
        th{background:#003366;color:white;font-size:8pt}tr{page-break-inside:avoid}thead{display:table-header-group}
        </style></head><body>';
    foreach ($preamble as $index => $caption) {
        $tag = $index === 1 ? 'h1' : 'p';
        $html .= "<{$tag}>" . $escape($caption) . "</{$tag}>";
    }
    $html .= '<table><thead><tr>';
    foreach ($columns as $label) {
        $html .= '<th>' . $escape($label) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    $cells = 0;
    foreach ($rows as $row) {
        $cells += count($columns);
        if ($cells > 4500 || strlen($html) > 900000) {
            throw new DomainException('Narrow your filters for PDF generation, or select CSV.');
        }
        $html .= '<tr>';
        foreach (report_values($row, $columns) as $value) {
            $html .= '<td>' . $escape($value) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table><p>' . $escape($footer) . '</p></body></html>';
    $options = new Options();
    $options->setIsRemoteEnabled(false);
    $options->setIsPhpEnabled(false);
    $options->setIsJavascriptEnabled(false);
    $options->setFontCache(sys_get_temp_dir());
    $document = new Dompdf($options);
    $document->setPaper('A4', count($columns) > 5 ? 'landscape' : 'portrait');
    $document->loadHtml($html, 'UTF-8');
    $document->render();
    $canvas = $document->getCanvas();
    $font = $document->getFontMetrics()->getFont('Helvetica', 'normal');
    $canvas->page_text(28, $canvas->get_height() - 25, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8);
    fwrite($stream, $document->output());
}

/** Call after the caller authorizes the report or catalog export, before any HTML. */
function download_table(string $format, string $title, array $columns, iterable $rows, array $metadata = []): never
{
    $types = [
        'pdf' => 'application/pdf',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv; charset=UTF-8',
    ];
    if (!isset($types[$format]) || !$columns) {
        throw new DomainException('Select a supported export format.');
    }
    $metadata += ['company' => 'Camotes Electric Cooperative, Inc.', 'generated_at' => date('Y-m-d H:i T'),
        'generated_by' => '', 'footer' => '', 'note' => '', 'template' => false];
    $preamble = [$metadata['company'], $title, 'Generated ' . $metadata['generated_at']];
    if ($metadata['generated_by'] !== '') {
        $preamble[] = 'Generated by ' . $metadata['generated_by'];
    }
    if ($metadata['note'] !== '') {
        $preamble[] = $metadata['note'];
    }
    $stream = tmpfile();
    if ($stream === false) {
        throw new RuntimeException('Temporary report storage is unavailable.');
    }
    try {
        if ($format === 'pdf') {
            write_report_pdf($stream, $columns, $rows, $preamble, $metadata['footer']);
        } elseif ($format === 'xlsx') {
            write_report_workbook($stream, $columns, $rows, $metadata['template'] ? [] : $preamble);
        } else {
            write_report_csv($stream, $columns, $rows, $metadata['template'] ? [] : $preamble);
        }
        rewind($stream);
        $base = trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-') ?: 'Inventory';
        header('Content-Type: ' . $types[$format]);
        header('Content-Disposition: attachment; filename="' . substr($base, 0, 80) . '-' . date('Ymd') . '.' . $format . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        fpassthru($stream);
    } finally {
        fclose($stream);
    }
    exit;
}
