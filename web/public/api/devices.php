<?php
/**
 * Intune Device API endpoints.
 *
 * GET  /api/devices.php                         – Device list for a month
 * GET  /api/devices.php?action=months           – Available months
 * GET  /api/devices.php?action=stats            – Summary stats
 * POST /api/devices.php { action: "sync" }      – Sync from Intune
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
        require_once __DIR__ . '/../../src/intune.php';
        try {
            $result = syncIntuneDevices($user['username']);
            echo json_encode(['message' => "{$result['devices']} Geraete synchronisiert, {$result['cmdb']} mit CMDB angereichert ({$result['month']})"]);
        } catch (\Exception $e) {
            AppLogger::error('device-sync', $e->getMessage(), [], $user['username']);
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
}

$pdo = getDb();

if ($action === 'months') {
    $months = $pdo->query("SELECT DISTINCT export_month FROM intune_device ORDER BY export_month DESC")
        ->fetchAll(\PDO::FETCH_COLUMN);
    echo json_encode($months);
    exit;
}

if ($action === 'stats') {
    $month = $pdo->query("SELECT MAX(export_month) FROM intune_device")->fetchColumn() ?: '';
    $stats = ['month' => $month, 'total' => 0, 'by_manufacturer' => []];
    if ($month) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM intune_device WHERE export_month = :m");
        $stmt->execute(['m' => $month]);
        $stats['total'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COALESCE(manufacturer, 'Unbekannt') AS manufacturer, COUNT(*) AS cnt
            FROM intune_device WHERE export_month = :m
            GROUP BY manufacturer ORDER BY cnt DESC
        ");
        $stmt->execute(['m' => $month]);
        $stats['by_manufacturer'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    echo json_encode($stats);
    exit;
}

// Default: device list
$month = $_GET['month'] ?? '';
if (!$month) {
    $month = $pdo->query("SELECT MAX(export_month) FROM intune_device")->fetchColumn() ?: date('Y-m');
}

$stmt = $pdo->prepare("
    SELECT device_name, serial_number, manufacturer, model,
           user_display_name, user_principal_name, compliance_state,
           last_sync, cost_center, company_name
    FROM intune_device
    WHERE export_month = :month
    ORDER BY user_display_name, device_name
");
$stmt->execute(['month' => $month]);

echo json_encode([
    'month' => $month,
    'devices' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
]);
