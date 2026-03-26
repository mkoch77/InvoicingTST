<?php
/**
 * Netbox Device API endpoints.
 *
 * GET  /api/netbox.php                             – All networking devices
 * GET  /api/netbox.php?category=switch             – Filter by category
 * GET  /api/netbox.php?action=months               – Available months
 * GET  /api/netbox.php?action=stats                – Summary stats
 * POST /api/netbox.php { action: "sync" }          – Sync from Netbox
 */

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/logger.php';

header('Content-Type: application/json');
$user = requireAuth();

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? $action;

    if ($action === 'sync-cmdb') {
        requireRole('admin');
        require_once __DIR__ . '/../../src/netbox_cmdb_sync.php';
        try {
            $limit = (int) ($input['limit'] ?? 0);
            $result = syncNetboxToCmdb($limit, $user['username']);
            echo json_encode(['message' => "{$result['created']} erstellt, {$result['updated']} aktualisiert, {$result['errors']} Fehler (von {$result['total']} Geräten)"]);
        } catch (\Exception $e) {
            AppLogger::error('netbox-cmdb-sync', $e->getMessage(), [], $user['username']);
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'sync') {
        requireRole('admin');
        require_once __DIR__ . '/../../src/netbox.php';
        try {
            $result = syncNetboxDevices($user['username']);
            $parts = [];
            if ($result['switches'] > 0) $parts[] = "{$result['switches']} Switches";
            if ($result['accesspoints'] > 0) $parts[] = "{$result['accesspoints']} Access Points";
            if ($result['routers'] > 0) $parts[] = "{$result['routers']} Router";
            echo json_encode(['message' => implode(', ', $parts) . " synchronisiert ({$result['month']})"]);
        } catch (\Exception $e) {
            AppLogger::error('netbox-sync', $e->getMessage(), [], $user['username']);
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
}

$pdo = getDb();

if ($action === 'months') {
    try {
        $months = $pdo->query("SELECT DISTINCT export_month FROM netbox_device ORDER BY export_month DESC")
            ->fetchAll(\PDO::FETCH_COLUMN);
        echo json_encode($months);
    } catch (\Exception $e) {
        echo json_encode([]);
    }
    exit;
}

if ($action === 'stats') {
    try {
        $month = $pdo->query("SELECT MAX(export_month) FROM netbox_device")->fetchColumn() ?: '';
        $stats = ['month' => $month, 'switches' => 0, 'accesspoints' => 0, 'routers' => 0, 'total' => 0];
        if ($month) {
            $s = $pdo->prepare("SELECT category, COUNT(*) AS cnt FROM netbox_device WHERE export_month = :m GROUP BY category");
            $s->execute(['m' => $month]);
            foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                if ($r['category'] === 'switch') $stats['switches'] = (int) $r['cnt'];
                if ($r['category'] === 'accesspoint') $stats['accesspoints'] = (int) $r['cnt'];
                if ($r['category'] === 'router') $stats['routers'] = (int) $r['cnt'];
            }
            $stats['total'] = $stats['switches'] + $stats['accesspoints'] + $stats['routers'];
        }
        echo json_encode($stats);
    } catch (\Exception $e) {
        echo json_encode(['month' => '', 'switches' => 0, 'accesspoints' => 0, 'routers' => 0, 'total' => 0]);
    }
    exit;
}

// Default: device list (all categories or filtered)
$category = $_GET['category'] ?? '';
$month = $_GET['month'] ?? '';
if (!$month) {
    try {
        $month = $pdo->query("SELECT MAX(export_month) FROM netbox_device")->fetchColumn() ?: date('Y-m');
    } catch (\Exception $e) {
        $month = date('Y-m');
    }
}

try {
    if ($category) {
        $stmt = $pdo->prepare("
            SELECT name, device_type, device_role, manufacturer, model, serial_number,
                   asset_tag, site, location, rack, status, primary_ip, tenant, category, description
            FROM netbox_device
            WHERE category = :cat AND export_month = :month
            ORDER BY site, name
        ");
        $stmt->execute(['cat' => $category, 'month' => $month]);
    } else {
        $stmt = $pdo->prepare("
            SELECT name, device_type, device_role, manufacturer, model, serial_number,
                   asset_tag, site, location, rack, status, primary_ip, tenant, category, description
            FROM netbox_device
            WHERE export_month = :month
            ORDER BY category, site, name
        ");
        $stmt->execute(['month' => $month]);
    }

    echo json_encode([
        'month' => $month,
        'category' => $category ?: 'all',
        'devices' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
    ]);
} catch (\Exception $e) {
    echo json_encode(['month' => $month, 'category' => $category ?: 'all', 'devices' => []]);
}
