<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/vms.php';

requireAuth();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

try {
    $month = $_GET['month'] ?? null;
    $rows = fetchVMs($month);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Virtual Machines');

    // Header — identisch zum PowerShell-Export
    $headers = [
        'A' => ['title' => 'Hostname',                 'width' => 25],
        'B' => ['title' => 'DNS Name',                 'width' => 30],
        'C' => ['title' => 'IP Address',               'width' => 20],
        'D' => ['title' => 'Operating System',         'width' => 30],
        'E' => ['title' => 'vCPU',                     'width' =>  8],
        'F' => ['title' => 'vRAM (MB)',                'width' => 12],
        'G' => ['title' => 'Used Storage (GB)',        'width' => 18],
        'H' => ['title' => 'Provisioned Storage (GB)', 'width' => 22],
        'I' => ['title' => 'Power State',              'width' => 14],
        'J' => ['title' => 'Duplicate',                'width' => 12],
    ];

    foreach ($headers as $col => $def) {
        $sheet->setCellValue($col . '1', $def['title']);
        $sheet->getColumnDimension($col)->setWidth($def['width']);
    }

    // Header Style: bold, white on blue (TableStyle Medium2)
    $headerStyle = [
        'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4472C4']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ];
    $sheet->getStyle('A1:J1')->applyFromArray($headerStyle);

    // Freeze top row
    $sheet->freezePane('A2');

    // IP Address column as text
    $sheet->getStyle('C:C')->getNumberFormat()->setFormatCode('@');

    // Detect duplicate hostnames
    $hostCount = [];
    foreach ($rows as $r) {
        $h = strtolower($r['hostname'] ?? '');
        $hostCount[$h] = ($hostCount[$h] ?? 0) + 1;
    }
    $dupeNames = array_keys(array_filter($hostCount, fn($c) => $c > 1));

    // Data rows
    $rowNum = 2;
    foreach ($rows as $r) {
        $ips = implode(', ', $r['ip_addresses'] ?? []);
        $isDupe = in_array(strtolower($r['hostname'] ?? ''), $dupeNames);

        $sheet->setCellValue("A{$rowNum}", $r['hostname'] ?? '');
        $sheet->setCellValue("B{$rowNum}", $r['dns_name'] ?? '');
        $sheet->setCellValueExplicit("C{$rowNum}", $ips, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue("D{$rowNum}", $r['operating_system'] ?? '');
        $sheet->setCellValue("E{$rowNum}", $r['vcpu'] ?? 0);
        $sheet->setCellValue("F{$rowNum}", $r['vram_mb'] ?? 0);
        $sheet->setCellValue("G{$rowNum}", $r['used_storage_gb'] ?? 0);
        $sheet->setCellValue("H{$rowNum}", $r['provisioned_storage_gb'] ?? 0);
        $sheet->setCellValue("I{$rowNum}", $r['power_state'] ?? '');
        $sheet->setCellValue("J{$rowNum}", $isDupe ? 'Yes' : '');

        // Highlight duplicate rows yellow
        if ($isDupe) {
            $sheet->getStyle("A{$rowNum}:J{$rowNum}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFFFFF00');
        }

        $rowNum++;
    }

    // AutoFilter
    $lastRow = max($rowNum - 1, 1);
    $sheet->setAutoFilter("A1:J{$lastRow}");

    // Send file
    $dateStr = $month ?: date('Y-m');
    $filename = "VeeamOne_VMs_{$dateStr}.xlsx";

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
