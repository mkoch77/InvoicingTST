<?php
/**
 * Device Billing API — grouped by company → cost center → user.
 *
 * GET /api/billing-devices.php?month=YYYY-MM
 */

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/db.php';

header('Content-Type: application/json');
requireAuth();

try {
    $pdo = getDb();

    $months = $pdo->query("SELECT DISTINCT export_month FROM intune_device ORDER BY export_month DESC")
        ->fetchAll(\PDO::FETCH_COLUMN);
    $month = $_GET['month'] ?? ($months[0] ?? date('Y-m'));

    $stmt = $pdo->prepare("
        SELECT device_name, serial_number, manufacturer, model,
               user_display_name, user_principal_name, compliance_state,
               last_sync, cost_center, company_name, device_category, device_price
        FROM intune_device
        WHERE export_month = :month
        ORDER BY company_name, cost_center, user_display_name, device_name
    ");
    $stmt->execute(['month' => $month]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Load cost center info
    $ccInfo = [];
    $ccRows = $pdo->query("SELECT cc.name, cc.cost_bearer, co.name AS company_name, co.location AS company_address
        FROM cost_center cc LEFT JOIN company co ON co.id = cc.company_id")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($ccRows as $r) {
        $ccInfo[$r['name']] = $r;
    }

    // Group: company → cost_center → user → devices
    $companies = [];
    $totalDevices = 0;
    $totalPrice = 0.0;

    foreach ($rows as $row) {
        $ccNumber = $row['cost_center'] ?: 'Nicht zugeordnet';
        // Resolve company ONLY from Firmenstruktur (cost_center → company)
        $companyName = (isset($ccInfo[$ccNumber]) && $ccInfo[$ccNumber]['company_name'])
            ? $ccInfo[$ccNumber]['company_name'] : 'Nicht zugeordnet';
        $upn = $row['user_principal_name'] ?: 'Kein Benutzer';
        $price = (float) ($row['device_price'] ?? 0);

        if (!isset($companies[$companyName])) {
            $companyAddr = '';
            foreach ($ccInfo as $cc) {
                if (($cc['company_name'] ?? '') === $companyName && !empty($cc['company_address'])) {
                    $companyAddr = $cc['company_address'];
                    break;
                }
            }
            $companies[$companyName] = [
                'company' => $companyName,
                'address' => $companyAddr,
                'cost_centers' => [],
                'total_devices' => 0,
                'total_users' => 0,
                'total_price' => 0.0,
            ];
        }

        if (!isset($companies[$companyName]['cost_centers'][$ccNumber])) {
            $ci = $ccInfo[$ccNumber] ?? null;
            $companies[$companyName]['cost_centers'][$ccNumber] = [
                'number' => $ccNumber,
                'bearer' => $ci['cost_bearer'] ?? '',
                'users' => [],
                'total_devices' => 0,
                'total_price' => 0.0,
            ];
        }

        if (!isset($companies[$companyName]['cost_centers'][$ccNumber]['users'][$upn])) {
            $companies[$companyName]['cost_centers'][$ccNumber]['users'][$upn] = [
                'display_name' => $row['user_display_name'] ?: $upn,
                'upn' => $upn,
                'devices' => [],
                'total_price' => 0.0,
            ];
        }

        $companies[$companyName]['cost_centers'][$ccNumber]['users'][$upn]['devices'][] = [
            'name' => $row['device_name'],
            'serial' => $row['serial_number'],
            'manufacturer' => $row['manufacturer'],
            'model' => $row['model'],
            'category' => $row['device_category'],
            'price' => $price,
            'compliance' => $row['compliance_state'],
            'last_sync' => $row['last_sync'],
        ];

        $companies[$companyName]['cost_centers'][$ccNumber]['users'][$upn]['total_price'] += $price;
        $companies[$companyName]['cost_centers'][$ccNumber]['total_devices']++;
        $companies[$companyName]['cost_centers'][$ccNumber]['total_price'] += $price;
        $companies[$companyName]['total_devices']++;
        $companies[$companyName]['total_price'] += $price;
        $totalDevices++;
        $totalPrice += $price;
    }

    // Sort and convert to arrays
    ksort($companies);
    $totalUsers = 0;
    foreach ($companies as &$company) {
        ksort($company['cost_centers']);
        $companyUsers = [];
        foreach ($company['cost_centers'] as &$cc) {
            ksort($cc['users']);
            $cc['users'] = array_values($cc['users']);
            $cc['total_users'] = count($cc['users']);
            foreach ($cc['users'] as $u) {
                $companyUsers[$u['upn']] = true;
            }
        }
        $company['cost_centers'] = array_values($company['cost_centers']);
        $company['total_users'] = count($companyUsers);
        $totalUsers += count($companyUsers);
    }

    echo json_encode([
        'month' => $month,
        'months' => array_slice($months, 0, 6),
        'companies' => array_values($companies),
        'total_devices' => $totalDevices,
        'total_users' => $totalUsers,
        'total_price' => round($totalPrice, 2),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
