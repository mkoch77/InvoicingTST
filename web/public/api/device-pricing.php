<?php
/**
 * Device pricing API.
 *
 * GET  – List all device pricing categories
 * PUT  – Update prices { tiers: [{ id, price, is_active }] }
 */

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/db.php';

header('Content-Type: application/json');
$user = requireAuth();

$pdo = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query("SELECT id, category_name, price, is_active, sort_order FROM device_pricing ORDER BY sort_order")->fetchAll(\PDO::FETCH_ASSOC);
    echo json_encode($rows);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    requireRole('admin');
    $input = json_decode(file_get_contents('php://input'), true);
    $tiers = $input['tiers'] ?? [];

    $stmt = $pdo->prepare("UPDATE device_pricing SET price = :price, is_active = :active WHERE id = :id");
    foreach ($tiers as $t) {
        $stmt->execute([
            'price'  => (float) ($t['price'] ?? 0),
            'active' => !empty($t['is_active']) ? 'true' : 'false',
            'id'     => (int) ($t['id'] ?? 0),
        ]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
