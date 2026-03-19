<?php

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/logger.php';

header('Content-Type: application/json');

$currentUser = requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$level    = $_GET['level']    ?? null;
$category = $_GET['category'] ?? null;
$search   = $_GET['search']   ?? null;
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = max(1, (int) ($_GET['perPage'] ?? 100));
$offset   = ($page - 1) * $perPage;

$result = AppLogger::query($level, $category, $search, $perPage, $offset);

echo json_encode([
    'total'      => $result['total'],
    'logs'       => $result['logs'],
    'categories' => AppLogger::categories(),
    'page'       => $page,
    'perPage'    => $perPage,
]);
