<?php

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/vms.php';
require_once __DIR__ . '/../../src/pricing.php';

header('Content-Type: application/json');
requireAuth();

try {
    $month = $_GET['month'] ?? null;
    $vms = fetchVMs($month);

    foreach ($vms as &$vm) {
        enrichVmWithPricing($vm);
    }

    echo json_encode($vms);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
