<?php

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/vms.php';

header('Content-Type: application/json');
requireAuth();

try {
    echo json_encode(fetchMonths());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
