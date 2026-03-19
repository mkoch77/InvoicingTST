<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/vms.php';
require_once __DIR__ . '/../../src/pricing.php';

requireAuth();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

try {
    $month = $_GET['month'] ?? null;
    $rows = fetchVMs($month);

    foreach ($rows as &$r) {
        enrichVmWithPricing($r);
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Virtual Machines');

    $headers = [
        'A' => ['title' => 'Hostname',                 'width' => 25],
        'B' => ['title' => 'DNS Name',                 'width' => 30],
        'C' => ['title' => 'Kunde',                    'width' => 20],
        'D' => ['title' => 'IP Address',               'width' => 20],
        'E' => ['title' => 'Operating System',         'width' => 30],
        'F' => ['title' => 'vCPU',                     'width' =>  8],
        'G' => ['title' => 'vRAM (MB)',                'width' => 12],
        'H' => ['title' => 'Used Storage (GB)',        'width' => 18],
        'I' => ['title' => 'Provisioned Storage (GB)', 'width' => 22],
        'J' => ['title' => 'Power State',              'width' => 14],
        'K' => ['title' => 'Punkte',                   'width' => 12],
        'L' => ['title' => 'Klasse',                   'width' => 10],
        'M' => ['title' => 'Preis (EUR)',              'width' => 14],
        'N' => ['title' => 'Duplicate',                'width' => 12],
    ];

    foreach ($headers as $col => $def) {
        $sheet->setCellValue($col . '1', $def['title']);
        $sheet->getColumnDimension($col)->setWidth($def['width']);
    }

    $headerStyle = [
        'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4472C4']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ];
    $sheet->getStyle('A1:N1')->applyFromArray($headerStyle);
    $sheet->freezePane('A2');
    $sheet->getStyle('D:D')->getNumberFormat()->setFormatCode('@');

    // Detect duplicate hostnames
    $hostCount = [];
    foreach ($rows as $r) {
        $h = strtolower($r['hostname'] ?? '');
        $hostCount[$h] = ($hostCount[$h] ?? 0) + 1;
    }
    $dupeNames = array_keys(array_filter($hostCount, fn($c) => $c > 1));

    $rowNum = 2;
    foreach ($rows as $r) {
        $ips = implode(', ', $r['ip_addresses'] ?? []);
        $isDupe = in_array(strtolower($r['hostname'] ?? ''), $dupeNames);
        $customerLabel = '';
        if (!empty($r['customer_code']) && !empty($r['customer_name'])) {
            $customerLabel = $r['customer_code'] . ' – ' . $r['customer_name'];
        }

        $sheet->setCellValue("A{$rowNum}", $r['hostname'] ?? '');
        $sheet->setCellValue("B{$rowNum}", $r['dns_name'] ?? '');
        $sheet->setCellValue("C{$rowNum}", $customerLabel);
        $sheet->setCellValueExplicit("D{$rowNum}", $ips, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue("E{$rowNum}", $r['operating_system'] ?? '');
        $sheet->setCellValue("F{$rowNum}", $r['vcpu'] ?? 0);
        $sheet->setCellValue("G{$rowNum}", $r['vram_mb'] ?? 0);
        $sheet->setCellValue("H{$rowNum}", $r['used_storage_gb'] ?? 0);
        $sheet->setCellValue("I{$rowNum}", $r['provisioned_storage_gb'] ?? 0);
        $sheet->setCellValue("J{$rowNum}", $r['power_state'] ?? '');
        $sheet->setCellValue("K{$rowNum}", $r['points'] ?? 0);
        $sheet->setCellValue("L{$rowNum}", $r['pricing_class'] ?? '');
        $sheet->setCellValue("M{$rowNum}", $r['price'] ?? 0);
        $sheet->setCellValue("N{$rowNum}", $isDupe ? 'Yes' : '');

        // Format price column
        $sheet->getStyle("M{$rowNum}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("K{$rowNum}")->getNumberFormat()->setFormatCode('#,##0.00');

        if ($isDupe) {
            $sheet->getStyle("A{$rowNum}:N{$rowNum}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFFFFF00');
        }

        $rowNum++;
    }

    $lastRow = max($rowNum - 1, 1);
    $sheet->setAutoFilter("A1:N{$lastRow}");

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
