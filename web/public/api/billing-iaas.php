<?php
/**
 * IaaS Billing API — returns VM billing data grouped by cost center and company.
 *
 * GET /api/billing-iaas.php?month=YYYY-MM
 */

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/vms.php';
require_once __DIR__ . '/../../src/pricing.php';
require_once __DIR__ . '/../../src/db.php';

header('Content-Type: application/json');
requireAuth();

try {
    $months = fetchMonths();
    $month = $_GET['month'] ?? ($months[0] ?? null);

    $vms = fetchVMs($month);
    $pdo = getDb();

    // Load IaaS hostnames
    $iaasHostnames = [];
    $rows = $pdo->query("SELECT hostname FROM server_service_mapping WHERE it_service LIKE '%Iaas%Infrastructure%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $h) {
        $iaasHostnames[strtoupper($h)] = true;
    }

    // Load cost center → company mapping
    $ccToCompany = [];
    $ccRows = $pdo->query("
        SELECT cc.name AS cc_name, cc.cost_bearer, cc.address,
               co.name AS company_name, co.location AS company_address
        FROM cost_center cc
        LEFT JOIN company co ON co.id = cc.company_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ccRows as $r) {
        $ccToCompany[$r['cc_name']] = $r;
    }

    // Load cost center numbers from server_service_mapping
    $hostnameToCc = [];
    $ccRows2 = $pdo->query("SELECT hostname, cost_center_number FROM server_service_mapping WHERE cost_center_number IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ccRows2 as $r) {
        $hostnameToCc[strtoupper($r['hostname'])] = $r['cost_center_number'];
    }

    // Build billing data
    $billingData = []; // grouped by company → cost center → servers
    $totalPrice = 0.0;
    $totalCount = 0;

    foreach ($vms as &$vm) {
        $hostname = strtoupper($vm['hostname'] ?? '');
        if (!isset($iaasHostnames[$hostname])) continue;

        enrichVmWithPricing($vm);
        if (empty($vm['pricing_class']) || ($vm['price'] ?? 0) <= 0) continue;

        $ccNumber = $hostnameToCc[$hostname] ?? 'Nicht zugeordnet';
        $ccInfo = $ccToCompany[$ccNumber] ?? null;
        $companyName = $ccInfo['company_name'] ?? 'Unbekannt';
        $companyAddress = $ccInfo['company_address'] ?? '';
        $ccBearer = $ccInfo['cost_bearer'] ?? '';

        if (!isset($billingData[$companyName])) {
            $billingData[$companyName] = [
                'company' => $companyName,
                'address' => $companyAddress,
                'cost_centers' => [],
                'total_price' => 0.0,
                'total_count' => 0,
            ];
        }

        if (!isset($billingData[$companyName]['cost_centers'][$ccNumber])) {
            $billingData[$companyName]['cost_centers'][$ccNumber] = [
                'number' => $ccNumber,
                'bearer' => $ccBearer,
                'servers' => [],
                'total_price' => 0.0,
                'total_count' => 0,
            ];
        }

        $billingData[$companyName]['cost_centers'][$ccNumber]['servers'][] = [
            'hostname' => $vm['hostname'],
            'vcpu' => $vm['vcpu'],
            'vram_mb' => $vm['vram_mb'],
            'provisioned_storage_gb' => $vm['provisioned_storage_gb'],
            'points' => $vm['points'] ?? 0,
            'pricing_class' => $vm['pricing_class'] ?? '',
            'price' => $vm['price'] ?? 0,
            'power_state' => $vm['power_state'] ?? '',
            'cmdb_customer' => $vm['cmdb_customer'] ?? '',
        ];

        $price = (float) ($vm['price'] ?? 0);
        $billingData[$companyName]['cost_centers'][$ccNumber]['total_price'] += $price;
        $billingData[$companyName]['cost_centers'][$ccNumber]['total_count']++;
        $billingData[$companyName]['total_price'] += $price;
        $billingData[$companyName]['total_count']++;
        $totalPrice += $price;
        $totalCount++;
    }

    // Sort companies by name, cost centers by number
    ksort($billingData);
    foreach ($billingData as &$company) {
        ksort($company['cost_centers']);
        $company['cost_centers'] = array_values($company['cost_centers']);
    }

    echo json_encode([
        'month' => $month,
        'months' => $months,
        'companies' => array_values($billingData),
        'total_price' => round($totalPrice, 2),
        'total_count' => $totalCount,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
