<?php

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/customers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// GET: everyone can read customers
if ($method === 'GET') {
    requireAuth();
    echo json_encode(listCustomers());
    exit;
}

// Write operations require admin or operator
$user = requireRole('admin', 'operator');
$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'POST' && ($input['action'] ?? '') === 'sync_cmdb') {
    requireRole('admin');
    try {
        $result = syncCustomersFromCmdb();
        echo json_encode($result);
    } catch (\Exception $e) {
        http_response_code(502);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST') {
    if (empty($input['code']) || empty($input['name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Code and name required']);
        exit;
    }
    try {
        $customer = createCustomer($input['code'], $input['name']);
        http_response_code(201);
        echo json_encode($customer);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'customer_code_key')) {
            http_response_code(409);
            echo json_encode(['error' => 'Code already exists']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    exit;
}

if ($method === 'PUT') {
    $id = (int) ($input['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); exit; }
    echo json_encode(updateCustomer($id, $input));
    exit;
}

// DELETE not allowed — use PUT with is_active=false to deactivate

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
