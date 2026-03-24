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
    $licStats = ['month' => $licMonth, 'total_users' => 0, 'total_price' => 0.0, 'sku_count' => 0, 'by_sku' => []];
    if ($licMonth) {
        $lpStmt = $pdo->prepare("
            SELECT ls.display_name, ls.sku_part_number, ls.price, COUNT(ela.id) AS cnt
            FROM entra_license_assignment ela
            JOIN license_sku ls ON ls.id = ela.license_sku_id
            WHERE ela.export_month = :m
            GROUP BY ls.id, ls.display_name, ls.sku_part_number, ls.price
            ORDER BY ls.display_name
        ");
        $lpStmt->execute(['m' => $licMonth]);
        $licTotal = 0.0;
        $licTotalUsers = 0;
        $bySku = [];
        foreach ($lpStmt->fetchAll() as $lp) {
            $cnt = (int) $lp['cnt'];
            $licTotal += (float) $lp['price'] * $cnt;
            $licTotalUsers += $cnt;
            $bySku[] = [
                'name'  => $lp['display_name'],
                'sku'   => $lp['sku_part_number'],
                'count' => $cnt,
            ];
        }
        $licStats['total_users'] = $licTotalUsers;
        $licStats['sku_count']   = count($bySku);
        $licStats['total_price'] = round($licTotal, 2);
        $licStats['by_sku']      = $bySku;
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
        'devices'       => (function() use ($pdo) {
            $stats = ['month' => '', 'total' => 0, 'by_manufacturer' => []];
            try {
                $devMonth = $pdo->query("SELECT MAX(export_month) FROM intune_device")->fetchColumn() ?: '';
                $stats['month'] = $devMonth;
                if ($devMonth) {
                    $s = $pdo->prepare("SELECT COUNT(*) FROM intune_device WHERE export_month = :m");
                    $s->execute(['m' => $devMonth]);
                    $stats['total'] = (int) $s->fetchColumn();
                    $s = $pdo->prepare("SELECT COALESCE(manufacturer,'Unbekannt') AS name, COUNT(*) AS count FROM intune_device WHERE export_month = :m GROUP BY manufacturer ORDER BY count DESC");
                    $s->execute(['m' => $devMonth]);
                    $stats['by_manufacturer'] = $s->fetchAll(\PDO::FETCH_ASSOC);
                }
            } catch (\Exception $e) {
                // Table may not exist yet
            }
            return $stats;
        })(),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
