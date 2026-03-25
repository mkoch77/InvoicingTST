<?php
/**
 * Billing Snapshot API.
 *
 * GET  /api/billing-snapshot.php                    – List all snapshots
 * GET  /api/billing-snapshot.php?id=X               – Get specific snapshot
 * GET  /api/billing-snapshot.php?action=config       – Get billing config
 * POST { action: "create", month: "YYYY-MM" }       – Create snapshot manually
 * POST { action: "delete", id: X }                   – Delete snapshot
 * PUT  { config: { billing_day, billing_hour, billing_auto } } – Update config
 */

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/billing_snapshot.php';

header('Content-Type: application/json');
$user = requireAuth();

$action = $_GET['action'] ?? '';

// GET: config
if ($action === 'config') {
    echo json_encode(getBillingConfig());
    exit;
}

// GET: specific snapshot
if (!empty($_GET['id'])) {
    $snapshot = getSnapshot((int) $_GET['id']);
    if (!$snapshot) {
        http_response_code(404);
        echo json_encode(['error' => 'Snapshot nicht gefunden']);
        exit;
    }
    // Decode JSON fields
    $snapshot['summary'] = json_decode($snapshot['summary'], true);
    $snapshot['iaas_data'] = json_decode($snapshot['iaas_data'], true);
    $snapshot['license_data'] = json_decode($snapshot['license_data'], true);
    $snapshot['device_data'] = json_decode($snapshot['device_data'], true);
    echo json_encode($snapshot);
    exit;
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'create') {
        requireRole('admin');
        $month = $input['month'] ?? date('Y-m', strtotime('last month'));
        try {
            $summary = createBillingSnapshot($month, $user['username']);
            echo json_encode(['message' => "Snapshot fuer {$month} erstellt. Gesamtsumme: {$summary['grand_total']} EUR", 'summary' => $summary]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete') {
        requireRole('admin');
        $id = (int) ($input['id'] ?? 0);
        if (deleteSnapshot($id, $user['username'])) {
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Snapshot nicht gefunden']);
        }
        exit;
    }
}

// PUT: update config
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    requireRole('admin');
    $input = json_decode(file_get_contents('php://input'), true);
    $config = $input['config'] ?? [];

    $pdo = getDb();
    $stmt = $pdo->prepare("
        INSERT INTO billing_config (config_key, config_value, updated_at)
        VALUES (:key, :val, NOW())
        ON CONFLICT (config_key) DO UPDATE SET config_value = EXCLUDED.config_value, updated_at = NOW()
    ");

    $allowed = ['billing_day', 'billing_hour', 'billing_auto'];
    foreach ($config as $key => $val) {
        if (in_array($key, $allowed, true)) {
            $stmt->execute(['key' => $key, 'val' => (string) $val]);
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

// GET: list all snapshots
$snapshots = listSnapshots();
foreach ($snapshots as &$s) {
    $s['summary'] = json_decode($s['summary'], true);
}
echo json_encode($snapshots);
