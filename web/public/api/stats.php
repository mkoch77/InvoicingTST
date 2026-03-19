<?php

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/vms.php';

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

    foreach ($vms as $vm) {
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
    }

    $other = $total - $citrix - $server - $templates;

    echo json_encode([
        'month'         => $month,
        'total'         => $total,
        'off_suspended' => $offSuspended,
        'citrix'        => $citrix,
        'server'        => $server,
        'templates'     => $templates,
        'other'         => $other,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
