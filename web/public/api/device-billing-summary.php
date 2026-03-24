<?php
/**
 * Lightweight device billing summary (for dashboard card).
 * Returns only total_devices and total_price — no heavy grouping.
 */

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/db.php';

header('Content-Type: application/json');
requireAuth();

try {
    $pdo = getDb();
    $month = $_GET['month'] ?? '';
    if (!$month) {
        $month = $pdo->query("SELECT MAX(export_month) FROM intune_device")->fetchColumn() ?: date('Y-m');
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total_devices, COALESCE(SUM(device_price), 0) AS total_price
        FROM intune_device
        WHERE export_month = :month
    ");
    $stmt->execute(['month' => $month]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    echo json_encode([
        'month' => $month,
        'total_devices' => (int) $row['total_devices'],
        'total_price' => round((float) $row['total_price'], 2),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
