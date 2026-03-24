<?php
/**
 * Billing Overview API — all costs grouped by company → cost center.
 * Aggregates: IaaS VMs, Microsoft 365 Licenses, Client Devices.
 * Company is resolved via cost_center → company table (Firmenstruktur).
 *
 * GET /api/billing-overview.php?month=YYYY-MM
 * GET /api/billing-overview.php?month=YYYY-MM&company=FirmaName
 * GET /api/billing-overview.php?month=YYYY-MM&cost_center=12345
 */

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/pricing.php';

header('Content-Type: application/json');
requireAuth();

try {
    $pdo = getDb();

    // Available months (union of all sources)
    $months = $pdo->query("
        SELECT DISTINCT m FROM (
            SELECT DISTINCT TO_CHAR(exported_at, 'YYYY-MM') AS m FROM vm
            UNION SELECT DISTINCT export_month AS m FROM entra_license_assignment
            UNION SELECT DISTINCT export_month AS m FROM intune_device
        ) sub ORDER BY m DESC
    ")->fetchAll(\PDO::FETCH_COLUMN);

    $month = $_GET['month'] ?? ($months[0] ?? date('Y-m'));
    $filterCompany = $_GET['company'] ?? '';
    $filterCC = $_GET['cost_center'] ?? '';

    // ── Build cost_center → company lookup from Firmenstruktur ──
    $ccToCompany = [];
    $ccRows = $pdo->query("
        SELECT cc.name AS cc_name, cc.cost_bearer, co.name AS company_name
        FROM cost_center cc
        LEFT JOIN company co ON co.id = cc.company_id
    ")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($ccRows as $r) {
        $ccToCompany[$r['cc_name']] = [
            'company' => $r['company_name'] ?: 'Unbekannt',
            'bearer'  => $r['cost_bearer'] ?? '',
        ];
    }

    // Helper: resolve company ONLY from Firmenstruktur (cost_center → company)
    $resolveCompany = function(string $costCenter) use ($ccToCompany): string {
        if ($costCenter && $costCenter !== 'Nicht zugeordnet' && isset($ccToCompany[$costCenter])) {
            return $ccToCompany[$costCenter]['company'] ?: 'Nicht zugeordnet';
        }
        return 'Nicht zugeordnet';
    };

    // ── 1. IaaS Server costs ──
    $iaasHostnames = [];
    try {
        $rows = $pdo->query("SELECT hostname FROM server_service_mapping WHERE it_service LIKE '%Iaas%Infrastructure%'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($rows as $h) $iaasHostnames[strtoupper($h)] = true;
    } catch (\Exception $e) {}

    $vmStmt = $pdo->prepare("
        SELECT v.hostname, v.vcpu, v.vram_mb, v.provisioned_storage_gb,
               ssm.cost_center_number AS cost_center
        FROM vm v
        LEFT JOIN server_service_mapping ssm ON UPPER(ssm.hostname) = UPPER(v.hostname)
        WHERE v.exported_at >= :start::DATE AND v.exported_at < (:start::DATE + INTERVAL '1 month')
    ");
    $vmStmt->execute(['start' => $month . '-01']);

    $iaasItems = [];
    foreach ($vmStmt->fetchAll(\PDO::FETCH_ASSOC) as $vm) {
        $hn = strtoupper($vm['hostname']);
        if (!isset($iaasHostnames[$hn])) continue;
        enrichVmWithPricing($vm);
        if (($vm['price'] ?? 0) <= 0) continue;
        $cc = $vm['cost_center'] ?: 'Nicht zugeordnet';
        $iaasItems[] = [
            'company' => $resolveCompany($cc),
            'cost_center' => $cc,
            'description' => $vm['hostname'],
            'service' => 'IaaS Server Hosting',
            'detail' => $vm['pricing_class'] ?? '',
            'price' => (float) $vm['price'],
        ];
    }

    // ── 2. License costs ──
    $licStmt = $pdo->prepare("
        SELECT eu.cost_center, eu.display_name, ls.display_name AS license_name, ls.price
        FROM entra_license_assignment ela
        JOIN entra_user eu ON eu.id = ela.entra_user_id
        JOIN license_sku ls ON ls.id = ela.license_sku_id AND ls.is_active = TRUE
        WHERE ela.export_month = :month
    ");
    $licStmt->execute(['month' => $month]);
    $licItems = [];
    foreach ($licStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $cc = $row['cost_center'] ?: 'Nicht zugeordnet';
        $licItems[] = [
            'company' => $resolveCompany($cc),
            'cost_center' => $cc,
            'description' => $row['display_name'],
            'service' => 'Microsoft 365 Lizenzen',
            'detail' => $row['license_name'],
            'price' => (float) $row['price'],
        ];
    }

    // ── 3. Device costs ──
    $devStmt = $pdo->prepare("
        SELECT cost_center, user_display_name, device_name, device_category, device_price
        FROM intune_device
        WHERE export_month = :month AND device_price > 0
    ");
    $devStmt->execute(['month' => $month]);
    $devItems = [];
    foreach ($devStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $cc = $row['cost_center'] ?: 'Nicht zugeordnet';
        $devItems[] = [
            'company' => $resolveCompany($cc),
            'cost_center' => $cc,
            'description' => $row['device_name'] . ($row['user_display_name'] ? " ({$row['user_display_name']})" : ''),
            'service' => 'Client Services',
            'detail' => $row['device_category'] ?? '',
            'price' => (float) $row['device_price'],
        ];
    }

    // ── Merge all items ──
    $allItems = array_merge($iaasItems, $licItems, $devItems);

    // Apply filters
    if ($filterCompany) {
        $allItems = array_filter($allItems, fn($i) => $i['company'] === $filterCompany);
    }
    if ($filterCC) {
        $allItems = array_filter($allItems, fn($i) => $i['cost_center'] === $filterCC);
    }

    // Group by company → cost center → service
    $companies = [];
    $grandTotal = 0.0;

    foreach ($allItems as $item) {
        $co = $item['company'];
        $cc = $item['cost_center'];
        $svc = $item['service'];

        if (!isset($companies[$co])) {
            $companies[$co] = ['company' => $co, 'cost_centers' => [], 'total_price' => 0.0];
        }
        if (!isset($companies[$co]['cost_centers'][$cc])) {
            $bearer = $ccToCompany[$cc]['bearer'] ?? '';
            $companies[$co]['cost_centers'][$cc] = ['number' => $cc, 'bearer' => $bearer, 'services' => [], 'total_price' => 0.0];
        }
        if (!isset($companies[$co]['cost_centers'][$cc]['services'][$svc])) {
            $companies[$co]['cost_centers'][$cc]['services'][$svc] = ['service' => $svc, 'items' => [], 'total_price' => 0.0, 'count' => 0];
        }

        $companies[$co]['cost_centers'][$cc]['services'][$svc]['items'][] = $item;
        $companies[$co]['cost_centers'][$cc]['services'][$svc]['total_price'] += $item['price'];
        $companies[$co]['cost_centers'][$cc]['services'][$svc]['count']++;
        $companies[$co]['cost_centers'][$cc]['total_price'] += $item['price'];
        $companies[$co]['total_price'] += $item['price'];
        $grandTotal += $item['price'];
    }

    // Sort and convert to arrays
    ksort($companies);
    foreach ($companies as &$co) {
        ksort($co['cost_centers']);
        foreach ($co['cost_centers'] as &$cc) {
            ksort($cc['services']);
            $cc['services'] = array_values($cc['services']);
        }
        $co['cost_centers'] = array_values($co['cost_centers']);
    }

    // Collect filter options (from unfiltered data)
    $unfilteredAll = array_merge($iaasItems, $licItems, $devItems);
    $allCompanies = array_unique(array_map(fn($i) => $i['company'], $unfilteredAll));
    sort($allCompanies);
    $allCostCenters = array_unique(array_map(fn($i) => $i['cost_center'], $unfilteredAll));
    sort($allCostCenters);

    echo json_encode([
        'month' => $month,
        'months' => array_slice($months, 0, 12),
        'companies' => array_values($companies),
        'total_price' => round($grandTotal, 2),
        'filter_companies' => array_values($allCompanies),
        'filter_cost_centers' => array_values($allCostCenters),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
