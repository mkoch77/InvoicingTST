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
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
