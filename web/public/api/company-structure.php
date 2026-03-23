<?php
/**
 * API endpoint for company structure (TST companies + CostCenters from CMDB).
 *
 * GET  – Returns cached company structure from DB
 * POST { action: "sync" } – Syncs from Jira Assets CMDB
 */

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/logger.php';

header('Content-Type: application/json');
$user = requireRole('admin', 'user');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pdo = getDb();

    // Get all companies
    $companies = $pdo->query("
        SELECT id, cmdb_key, name, location, status
        FROM company
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Get all cost centers
    $costCenters = $pdo->query("
        SELECT cc.id, cc.cmdb_key, cc.name, cc.cost_bearer, cc.address, cc.customer, cc.status, cc.company_id
        FROM cost_center cc
        ORDER BY cc.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Group cost centers by company_id
    $ccByCompany = [];
    foreach ($costCenters as $cc) {
        $ccByCompany[$cc['company_id']][] = $cc;
    }

    // Build response
    $result = [];
    foreach ($companies as $c) {
        $c['cost_centers'] = $ccByCompany[$c['id']] ?? [];
        $result[] = $c;
    }

    echo json_encode($result);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (($input['action'] ?? '') !== 'sync') {
        http_response_code(400);
        echo json_encode(['error' => 'action=sync erforderlich']);
        exit;
    }

    require_once __DIR__ . '/../../src/company.php';

    try {
        $result = syncCompanyStructure($user['username']);
        $servers = $result['servers'] ?? 0;
        echo json_encode(['message' => "{$result['companies']} Firmen, {$result['cost_centers']} Kostenstellen, {$servers} Server synchronisiert"]);

    } catch (\Exception $ex) {
        AppLogger::error('company-sync', $ex->getMessage(), [], $user['username']);
        http_response_code(500);
        echo json_encode(['error' => $ex->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
