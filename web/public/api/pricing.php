<?php

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/pricing.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    requireAuth();
    echo json_encode([
        'factors' => listPricingFactors(),
        'tiers'   => listPricingTiers(),
    ]);
    exit;
}

// Write operations require admin
requireRole('admin');
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$db = getDb();

if ($method === 'PUT') {
    // Update factors
    if (!empty($input['factors'])) {
        $stmt = $db->prepare("UPDATE pricing_factor SET points_per_unit = :pts, updated_at = NOW() WHERE resource = :res");
        foreach ($input['factors'] as $f) {
            $stmt->execute(['pts' => (float) $f['points_per_unit'], 'res' => $f['resource']]);
        }
    }

    // Update tier prices
    if (!empty($input['tiers'])) {
        $stmt = $db->prepare("UPDATE pricing_tier SET price = :price, max_points = :pts, updated_at = NOW() WHERE id = :id");
        foreach ($input['tiers'] as $t) {
            $stmt->execute(['price' => (float) $t['price'], 'pts' => (float) $t['max_points'], 'id' => (int) $t['id']]);
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
