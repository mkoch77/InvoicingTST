<?php

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/vms.php';
require_once __DIR__ . '/../../src/pricing.php';

header('Content-Type: application/json');
requireAuth();

try {
    $months = fetchMonths();
    $month = $months[0] ?? null;

    $vms = fetchVMs($month);

    $total = count($vms);
    $offSuspended = 0;
    $citrix = 0;
    $server = 0;
    $templates = 0;

    // Pricing aggregation: count per class + total price (only IaaS servers)
    $classCounts = [];
    $totalPrice = 0.0;

    // Load IaaS server hostnames from service mapping
    $pdo = getDb();
    $iaasHostnames = [];
    $rows = $pdo->query("SELECT hostname FROM server_service_mapping WHERE it_service LIKE '%Iaas%Infrastructure%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $h) {
        $iaasHostnames[strtoupper($h)] = true;
    }

    foreach ($vms as &$vm) {
        $hostname = strtoupper($vm['hostname'] ?? '');
        $state = $vm['power_state'] ?? '';

        if ($state === 'Off' || $state === 'Suspended') {
            $offSuspended++;
        }

        if (str_contains($hostname, 'TEMP')) {
            $templates++;
        } elseif (str_starts_with($hostname, 'CLT')) {
            $citrix++;
        } elseif (str_starts_with($hostname, 'F0')) {
            $server++;
        }

        // Enrich with pricing — only count IaaS servers for billing
        enrichVmWithPricing($vm);
        $isIaas = isset($iaasHostnames[$hostname]);
        if ($isIaas && !empty($vm['pricing_class']) && $vm['price'] > 0) {
            $cls = $vm['pricing_class'];
            if (!isset($classCounts[$cls])) {
                $classCounts[$cls] = ['count' => 0, 'price' => (float) $vm['price']];
            }
            $classCounts[$cls]['count']++;
            $totalPrice += (float) $vm['price'];
        }
    }

    $other = $total - $citrix - $server - $templates;

    // Sort class counts by tier order
    $tiers = getPricingTiers();
    $tierOrder = [];
    foreach ($tiers as $i => $t) {
        $tierOrder[$t['class_name']] = $i;
    }
    uksort($classCounts, function ($a, $b) use ($tierOrder) {
        return ($tierOrder[$a] ?? 999) - ($tierOrder[$b] ?? 999);
    });

    // License stats
    $licMonth = $pdo->query("SELECT MAX(export_month) FROM entra_license_assignment")->fetchColumn() ?: '';
    $licStats = ['month' => $licMonth, 'total_users' => 0, 'total_price' => 0.0, 'sku_count' => 0];
    if ($licMonth) {
        $licStmt = $pdo->prepare("
            SELECT COUNT(ela.id) AS cnt, SUM(ls.price) AS price_sum, COUNT(DISTINCT ls.id) AS sku_cnt
            FROM entra_license_assignment ela
            JOIN license_sku ls ON ls.id = ela.license_sku_id
            WHERE ela.export_month = :m
        ");
        $licStmt->execute(['m' => $licMonth]);
        $lr = $licStmt->fetch();
        $licStats['total_users'] = (int) ($lr['cnt'] ?? 0);
        $licStats['sku_count']   = (int) ($lr['sku_cnt'] ?? 0);

        // Calculate total price (users × per-license price)
        $lpStmt = $pdo->prepare("
            SELECT ls.price, COUNT(ela.id) AS cnt
            FROM entra_license_assignment ela
            JOIN license_sku ls ON ls.id = ela.license_sku_id
            WHERE ela.export_month = :m
            GROUP BY ls.id, ls.price
        ");
        $lpStmt->execute(['m' => $licMonth]);
        $licTotal = 0.0;
        foreach ($lpStmt->fetchAll() as $lp) {
            $licTotal += (float) $lp['price'] * (int) $lp['cnt'];
        }
        $licStats['total_price'] = round($licTotal, 2);
    }

    echo json_encode([
        'month'         => $month,
        'total'         => $total,
        'off_suspended' => $offSuspended,
        'citrix'        => $citrix,
        'server'        => $server,
        'templates'     => $templates,
        'other'         => $other,
        'billing'       => [
            'class_counts' => $classCounts,
            'total_price'  => round($totalPrice, 2),
            'vm_count'     => array_sum(array_column($classCounts, 'count')),
        ],
        'licenses'      => $licStats,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
