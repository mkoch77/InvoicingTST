<?php

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/customers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// POST: assign customer to VM (manual override)
if ($method === 'POST') {
    requireRole('admin', 'operator');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $hostname = trim($input['hostname'] ?? '');
    $customerId = (int) ($input['customer_id'] ?? 0);

    if (!$hostname) {
        http_response_code(400);
        echo json_encode(['error' => 'hostname required']);
        exit;
    }

    if ($customerId > 0) {
        setCustomerOverride($hostname, $customerId);

        // Also update all VM rows with this hostname
        $db = getDb();
        $db->prepare("UPDATE vm SET customer_id = :cid WHERE hostname = :hostname")
           ->execute(['cid' => $customerId, 'hostname' => $hostname]);
    } else {
        removeCustomerOverride($hostname);

        // Re-resolve: try auto-detect
        $codeMap = getCustomerCodeMap();
        $code = extractCustomerCode($hostname);
        $newCid = ($code && isset($codeMap[$code])) ? $codeMap[$code] : null;

        $db = getDb();
        $db->prepare("UPDATE vm SET customer_id = :cid WHERE hostname = :hostname")
           ->execute(['cid' => $newCid, 'hostname' => $hostname]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// PUT: run auto-assignment for a month
if ($method === 'PUT') {
    requireRole('admin', 'operator');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $month = $input['month'] ?? null;
    $updated = assignCustomersToVMs($month);
    echo json_encode(['updated' => $updated]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
