<?php
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/logger.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Public: return configurator settings (without margin for non-admins)
    $pdo = getDb();
    $row = $pdo->query('SELECT * FROM configurator_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['error' => 'Keine Konfiguration gefunden']);
        http_response_code(404);
        exit;
    }

    $result = [
        'network_price_per_sqm'      => (float) $row['network_price_per_sqm'],
        'ap_price'                    => (float) $row['ap_price'],
        'switch_price_monthly'        => (float) $row['switch_price_monthly'],
        'wp_admin_price_monthly'      => (float) $row['wp_admin_price_monthly'],
        'wp_operative_price_monthly'  => (float) $row['wp_operative_price_monthly'],
        'wp_scanner_price_monthly'    => (float) $row['wp_scanner_price_monthly'],
        'lic_m365_price_monthly'      => (float) $row['lic_m365_price_monthly'],
        'lic_erp_price_monthly'       => (float) $row['lic_erp_price_monthly'],
    ];

    // Only include margin for admin users
    $user = currentUser();
    if ($user && $user['role'] === 'admin') {
        $result['margin_percent'] = (float) $row['margin_percent'];
    }

    echo json_encode($result);
    exit;
}

if ($method === 'PUT') {
    requireRole('admin');
    $input = json_decode(file_get_contents('php://input'), true);

    $fields = [
        'margin_percent', 'network_price_per_sqm', 'ap_price', 'switch_price_monthly',
        'wp_admin_price_monthly', 'wp_operative_price_monthly', 'wp_scanner_price_monthly',
        'lic_m365_price_monthly', 'lic_erp_price_monthly',
    ];

    $sets = [];
    $params = [];
    foreach ($fields as $f) {
        if (isset($input[$f])) {
            $sets[] = "$f = :$f";
            $params[$f] = (float) $input[$f];
        }
    }

    if (empty($sets)) {
        echo json_encode(['error' => 'Keine Felder zum Aktualisieren']);
        http_response_code(400);
        exit;
    }

    $pdo = getDb();
    $sql = 'UPDATE configurator_settings SET ' . implode(', ', $sets) . ' WHERE id = 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    AppLogger::info('configurator', 'Kalkulator-Einstellungen aktualisiert', $params);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
