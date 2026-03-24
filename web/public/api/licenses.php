<?php
/**
 * License API endpoints.
 *
 * GET  /api/licenses.php                    – License overview (counts per SKU)
 * GET  /api/licenses.php?action=users&month=YYYY-MM&sku=PART – Users for a specific license
 * GET  /api/licenses.php?action=months      – Available months
 * POST /api/licenses.php { action: "sync" } – Sync from Entra ID
 */

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/logger.php';

header('Content-Type: application/json');
$user = requireAuth();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? $action;

    if ($action === 'sync') {
        requireRole('admin');
        require_once __DIR__ . '/../../src/msgraph.php';
        try {
            $result = syncEntraLicenses($user['username']);
            echo json_encode(['message' => "{$result['users']} Benutzer, {$result['assignments']} Lizenzzuweisungen synchronisiert ({$result['month']})"]);
        } catch (\Exception $e) {
            AppLogger::error('license-sync', $e->getMessage(), [], $user['username']);
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'update_skus') {
        requireRole('admin');
        $pdo = getDb();
        $skus = $input['skus'] ?? [];
        $stmt = $pdo->prepare("UPDATE license_sku SET price = :price, is_active = :active WHERE id = :id");
        foreach ($skus as $s) {
            $stmt->execute([
                'price'  => (float) ($s['price'] ?? 0),
                'active' => !empty($s['is_active']) ? 'true' : 'false',
                'id'     => (int) ($s['id'] ?? 0),
            ]);
        }
        AppLogger::info('license', 'Lizenzpreise aktualisiert', null, $user['username']);
        echo json_encode(['ok' => true]);
        exit;
    }
}

$pdo = getDb();

if ($action === 'months') {
    $months = $pdo->query("
        SELECT DISTINCT export_month FROM entra_license_assignment ORDER BY export_month DESC
    ")->fetchAll(\PDO::FETCH_COLUMN);
    echo json_encode($months);
    exit;
}

if ($action === 'users') {
    $month = $_GET['month'] ?? '';
    $sku   = $_GET['sku'] ?? '';

    if (!$month) {
        $month = $pdo->query("SELECT MAX(export_month) FROM entra_license_assignment")->fetchColumn() ?: date('Y-m');
    }

    $query = "
        SELECT eu.display_name, eu.user_principal_name, eu.department,
               eu.street_address, eu.city, eu.company_name,
               ls.sku_part_number, ls.display_name AS license_name
        FROM entra_license_assignment ela
        JOIN entra_user eu ON eu.id = ela.entra_user_id
        JOIN license_sku ls ON ls.id = ela.license_sku_id
        WHERE ela.export_month = :month
    ";
    $params = ['month' => $month];

    if ($sku) {
        $query .= " AND ls.sku_part_number = :sku";
        $params['sku'] = $sku;
    }

    $query .= " ORDER BY eu.display_name";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(\PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'skus') {
    $skus = $pdo->query("SELECT id, sku_id, sku_part_number, display_name, price, is_active FROM license_sku ORDER BY display_name")->fetchAll(\PDO::FETCH_ASSOC);
    echo json_encode($skus);
    exit;
}

// Default: overview — license counts for latest month
$month = $_GET['month'] ?? '';
if (!$month) {
    $month = $pdo->query("SELECT MAX(export_month) FROM entra_license_assignment")->fetchColumn() ?: date('Y-m');
}

$stmt = $pdo->prepare("
    SELECT ls.sku_part_number, ls.display_name, ls.price,
           COUNT(ela.id) AS user_count
    FROM license_sku ls
    LEFT JOIN entra_license_assignment ela ON ela.license_sku_id = ls.id AND ela.export_month = :month
    WHERE ls.is_active = TRUE
    GROUP BY ls.id, ls.sku_part_number, ls.display_name, ls.price
    HAVING COUNT(ela.id) > 0
    ORDER BY ls.display_name
");
$stmt->execute(['month' => $month]);
$licenses = $stmt->fetchAll(\PDO::FETCH_ASSOC);

$totalUsers = 0;
$totalPrice = 0.0;
foreach ($licenses as &$lic) {
    $lic['user_count'] = (int) $lic['user_count'];
    $lic['price'] = (float) $lic['price'];
    $lic['line_total'] = round($lic['user_count'] * $lic['price'], 2);
    $totalUsers += $lic['user_count'];
    $totalPrice += $lic['line_total'];
}

echo json_encode([
    'month'       => $month,
    'licenses'    => $licenses,
    'total_users' => $totalUsers,
    'total_price' => round($totalPrice, 2),
]);
