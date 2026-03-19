<?php

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/vms.php';

header('Content-Type: application/json');
requireAuth();

try {
    $month = $_GET['month'] ?? null;
    echo json_encode(fetchVMs($month));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
