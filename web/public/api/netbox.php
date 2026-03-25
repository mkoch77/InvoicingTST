<?php
/**
 * Netbox Device API endpoints.
 *
 * GET  /api/netbox.php?category=switch          – List switches
 * GET  /api/netbox.php?category=accesspoint     – List access points
 * GET  /api/netbox.php?action=months            – Available months
 * GET  /api/netbox.php?action=stats             – Summary stats
 * POST /api/netbox.php { action: "sync" }       – Sync from Netbox
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

    if ($action === 'sync') {
        requireRole('admin');
        require_once __DIR__ . '/../../src/netbox.php';
        try {
            $result = syncNetboxDevices($user['username']);
            echo json_encode(['message' => "{$result['switches']} Switches, {$result['accesspoints']} Access Points synchronisiert ({$result['month']})"]);
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
        $stats = ['month' => $month, 'switches' => 0, 'accesspoints' => 0];
        if ($month) {
            $s = $pdo->prepare("SELECT category, COUNT(*) AS cnt FROM netbox_device WHERE export_month = :m GROUP BY category");
            $s->execute(['m' => $month]);
            foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                if ($r['category'] === 'switch') $stats['switches'] = (int) $r['cnt'];
                if ($r['category'] === 'accesspoint') $stats['accesspoints'] = (int) $r['cnt'];
            }
        }
        echo json_encode($stats);
    } catch (\Exception $e) {
        echo json_encode(['month' => '', 'switches' => 0, 'accesspoints' => 0]);
    }
    exit;
}

// Default: device list
$category = $_GET['category'] ?? 'switch';
$month = $_GET['month'] ?? '';
if (!$month) {
    try {
        $month = $pdo->query("SELECT MAX(export_month) FROM netbox_device")->fetchColumn() ?: date('Y-m');
    } catch (\Exception $e) {
        $month = date('Y-m');
    }
}

try {
    $stmt = $pdo->prepare("
        SELECT name, device_type, device_role, manufacturer, model, serial_number,
               asset_tag, site, location, rack, status, primary_ip, tenant, description
        FROM netbox_device
        WHERE category = :cat AND export_month = :month
        ORDER BY site, name
    ");
    $stmt->execute(['cat' => $category, 'month' => $month]);

    echo json_encode([
        'month' => $month,
        'category' => $category,
        'devices' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
    ]);
} catch (\Exception $e) {
    echo json_encode(['month' => $month, 'category' => $category, 'devices' => []]);
}
