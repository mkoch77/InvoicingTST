<?php
/**
 * License Billing API — grouped by company → cost center → user.
 *
 * GET /api/billing-licenses.php?month=YYYY-MM
 */

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/db.php';

header('Content-Type: application/json');
requireAuth();

try {
    $pdo = getDb();

    // Available months
    $months = $pdo->query("SELECT DISTINCT export_month FROM entra_license_assignment ORDER BY export_month DESC")
        ->fetchAll(\PDO::FETCH_COLUMN);
    $month = $_GET['month'] ?? ($months[0] ?? date('Y-m'));

    // Fetch all assignments for the month with user + license info
    $stmt = $pdo->prepare("
        SELECT eu.display_name, eu.user_principal_name, eu.department,
               eu.cost_center, eu.company_name, eu.street_address, eu.city,
               ls.sku_part_number, ls.display_name AS license_name, ls.price
        FROM entra_license_assignment ela
        JOIN entra_user eu ON eu.id = ela.entra_user_id
        JOIN license_sku ls ON ls.id = ela.license_sku_id
        WHERE ela.export_month = :month AND ls.is_active = TRUE
        ORDER BY eu.company_name, eu.cost_center, eu.display_name, ls.display_name
    ");
    $stmt->execute(['month' => $month]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Load cost center → company address mapping
    $ccAddresses = [];
    $ccRows = $pdo->query("
        SELECT cc.name, cc.cost_bearer, cc.address, co.name AS company_name, co.location AS company_address
        FROM cost_center cc
        LEFT JOIN company co ON co.id = cc.company_id
    ")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($ccRows as $r) {
        $ccAddresses[$r['name']] = $r;
    }

    // Group: company → cost_center → user → licenses
    $companies = [];
    $totalPrice = 0.0;
    $totalAssignments = 0;

    foreach ($rows as $row) {
        // Resolve company ONLY from Firmenstruktur (cost_center → company)
        $companyName = (isset($ccAddresses[$ccNumber]) && $ccAddresses[$ccNumber]['company_name'])
            ? $ccAddresses[$ccNumber]['company_name'] : 'Nicht zugeordnet';
        $ccNumber = $row['cost_center'] ?: 'Nicht zugeordnet';
        $upn = $row['user_principal_name'];
        $price = (float) $row['price'];

        // Company level
        if (!isset($companies[$companyName])) {
            // Try to find address from cost_center table
            $companyAddr = '';
            foreach ($ccAddresses as $cc) {
                if (($cc['company_name'] ?? '') === $companyName && !empty($cc['company_address'])) {
                    $companyAddr = $cc['company_address'];
                    break;
                }
            }
            $companies[$companyName] = [
                'company' => $companyName,
                'address' => $companyAddr,
                'cost_centers' => [],
                'total_price' => 0.0,
                'total_assignments' => 0,
                'total_users' => 0,
            ];
        }

        // Cost center level
        if (!isset($companies[$companyName]['cost_centers'][$ccNumber])) {
            $ccInfo = $ccAddresses[$ccNumber] ?? null;
            $companies[$companyName]['cost_centers'][$ccNumber] = [
                'number' => $ccNumber,
                'bearer' => $ccInfo['cost_bearer'] ?? '',
                'users' => [],
                'total_price' => 0.0,
                'total_assignments' => 0,
            ];
        }

        // User level
        if (!isset($companies[$companyName]['cost_centers'][$ccNumber]['users'][$upn])) {
            $companies[$companyName]['cost_centers'][$ccNumber]['users'][$upn] = [
                'display_name' => $row['display_name'],
                'upn' => $upn,
                'department' => $row['department'] ?? '',
                'licenses' => [],
                'total_price' => 0.0,
            ];
        }

        // License
        $companies[$companyName]['cost_centers'][$ccNumber]['users'][$upn]['licenses'][] = [
            'name' => $row['license_name'],
            'sku' => $row['sku_part_number'],
            'price' => $price,
        ];

        $companies[$companyName]['cost_centers'][$ccNumber]['users'][$upn]['total_price'] += $price;
        $companies[$companyName]['cost_centers'][$ccNumber]['total_price'] += $price;
        $companies[$companyName]['cost_centers'][$ccNumber]['total_assignments']++;
        $companies[$companyName]['total_price'] += $price;
        $companies[$companyName]['total_assignments']++;
        $totalPrice += $price;
        $totalAssignments++;
    }

    // Count unique users per company and convert maps to arrays
    ksort($companies);
    foreach ($companies as &$company) {
        ksort($company['cost_centers']);
        $companyUsers = [];
        foreach ($company['cost_centers'] as &$cc) {
            ksort($cc['users']);
            $cc['users'] = array_values($cc['users']);
            foreach ($cc['users'] as $u) {
                $companyUsers[$u['upn']] = true;
            }
            $cc['total_users'] = count($cc['users']);
        }
        $company['cost_centers'] = array_values($company['cost_centers']);
        $company['total_users'] = count($companyUsers);
    }

    // Count total unique users
    $allUsers = [];
    foreach ($rows as $r) {
        $allUsers[$r['user_principal_name']] = true;
    }

    echo json_encode([
        'month' => $month,
        'months' => array_slice($months, 0, 6),
        'companies' => array_values($companies),
        'total_price' => round($totalPrice, 2),
        'total_assignments' => $totalAssignments,
        'total_users' => count($allUsers),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
