<?php
/**
 * Billing snapshot service: creates monthly frozen billing records.
 *
 * A snapshot captures all billing data (IaaS, Licenses, Devices) at the
 * configured billing date, including prices, quantities, and totals as
 * they were at that moment.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/logger.php';

/**
 * Get billing configuration.
 */
function getBillingConfig(): array
{
    $pdo = getDb();
    try {
        $rows = $pdo->query("SELECT config_key, config_value FROM billing_config")->fetchAll(\PDO::FETCH_KEY_PAIR);
    } catch (\Exception $e) {
        $rows = [];
    }
    $day = $rows['billing_day'] ?? '1';
    return [
        'billing_day'  => $day === 'last' ? 'last' : (int) $day,
        'billing_hour' => (int) ($rows['billing_hour'] ?? 6),
        'billing_auto' => ($rows['billing_auto'] ?? 'true') === 'true',
    ];
}

/**
 * Check if a snapshot should be created now.
 */
function shouldCreateSnapshot(): bool
{
    $config = getBillingConfig();
    if (!$config['billing_auto']) return false;

    $now = new \DateTime();
    $day = (int) $now->format('j');
    $hour = (int) $now->format('G');
    $billingDay = $config['billing_day'];

    // Resolve target day
    if ($billingDay === 'last') {
        $targetDay = (int) $now->format('t'); // last day of current month
    } else {
        $targetDay = (int) $billingDay;
    }

    if ($day !== $targetDay || $hour !== $config['billing_hour']) {
        return false;
    }

    // Check if snapshot for previous month already exists
    $prevMonth = (new \DateTime('first day of last month'))->format('Y-m');
    return !snapshotExists($prevMonth);
}

/**
 * Check if a snapshot exists for a given month.
 */
function snapshotExists(string $month): bool
{
    $pdo = getDb();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM billing_snapshot WHERE snapshot_month = :m");
    $stmt->execute(['m' => $month]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Create a billing snapshot for the given month.
 * Captures all current pricing and billing data frozen at this point.
 */
function createBillingSnapshot(string $month, string $username = 'system'): array
{
    $pdo = getDb();

    if (snapshotExists($month)) {
        throw new \RuntimeException("Snapshot fuer Monat {$month} existiert bereits.");
    }

    AppLogger::info('billing', "Creating billing snapshot for {$month}", [], $username);

    // ── Capture current pricing configuration ──
    $pricingFactors = [];
    try {
        $rows = $pdo->query("SELECT resource, points_per_unit, unit FROM pricing_factor")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $r) $pricingFactors[$r['resource']] = $r;
    } catch (\Exception $e) {}

    $pricingTiers = [];
    try {
        $pricingTiers = $pdo->query("SELECT class_name, max_points, price FROM pricing_tier ORDER BY sort_order")->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Exception $e) {}

    $licenseSkus = [];
    try {
        $licenseSkus = $pdo->query("SELECT sku_part_number, display_name, price, is_active FROM license_sku ORDER BY display_name")->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Exception $e) {}

    $devicePricing = [];
    try {
        $devicePricing = $pdo->query("SELECT category_name, price, is_active FROM device_pricing ORDER BY sort_order")->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Exception $e) {}

    // ── IaaS billing data ──
    $iaasData = collectIaasData($pdo, $month);

    // ── License billing data ──
    $licenseData = collectLicenseData($pdo, $month);

    // ── Device billing data ──
    $deviceData = collectDeviceData($pdo, $month);

    // ── Summary ──
    $summary = [
        'month' => $month,
        'created_at' => date('c'),
        'pricing_config' => [
            'factors' => $pricingFactors,
            'tiers' => $pricingTiers,
            'license_skus' => $licenseSkus,
            'device_pricing' => $devicePricing,
        ],
        'iaas' => [
            'count' => $iaasData['total_count'],
            'total_price' => $iaasData['total_price'],
        ],
        'licenses' => [
            'users' => $licenseData['total_users'],
            'assignments' => $licenseData['total_assignments'],
            'total_price' => $licenseData['total_price'],
        ],
        'devices' => [
            'count' => $deviceData['total_count'],
            'total_price' => $deviceData['total_price'],
        ],
        'grand_total' => round(
            $iaasData['total_price'] + $licenseData['total_price'] + $deviceData['total_price'], 2
        ),
    ];

    // ── Store snapshot ──
    $stmt = $pdo->prepare("
        INSERT INTO billing_snapshot (snapshot_month, created_by, summary, iaas_data, license_data, device_data)
        VALUES (:month, :user, :summary, :iaas, :license, :device)
    ");
    $stmt->execute([
        'month'   => $month,
        'user'    => $username,
        'summary' => json_encode($summary),
        'iaas'    => json_encode($iaasData),
        'license' => json_encode($licenseData),
        'device'  => json_encode($deviceData),
    ]);

    AppLogger::info('billing', "Snapshot created for {$month}: IaaS={$iaasData['total_count']}, Licenses={$licenseData['total_assignments']}, Devices={$deviceData['total_count']}, Total={$summary['grand_total']} EUR", [], $username);

    return $summary;
}

/**
 * Collect IaaS server billing data.
 */
function collectIaasData(\PDO $pdo, string $month): array
{
    $iaasHostnames = [];
    try {
        $rows = $pdo->query("SELECT hostname FROM server_service_mapping WHERE it_service LIKE '%Iaas%Infrastructure%'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($rows as $h) $iaasHostnames[strtoupper($h)] = true;
    } catch (\Exception $e) {}

    $stmt = $pdo->prepare("
        SELECT v.hostname, v.vcpu, v.vram_mb, v.provisioned_storage_gb,
               ssm.cost_center_number AS cost_center, ssm.cmdb_customer
        FROM vm v
        LEFT JOIN server_service_mapping ssm ON UPPER(ssm.hostname) = UPPER(v.hostname)
        WHERE v.exported_at >= :start::DATE AND v.exported_at < (:start::DATE + INTERVAL '1 month')
    ");
    $stmt->execute(['start' => $month . '-01']);

    // Resolve cost center → company
    $ccToCompany = [];
    $ccRows = $pdo->query("SELECT cc.name, co.name AS company_name FROM cost_center cc LEFT JOIN company co ON co.id = cc.company_id")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($ccRows as $r) $ccToCompany[$r['name']] = $r['company_name'] ?? '';

    $items = [];
    $totalPrice = 0.0;
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $vm) {
        $hn = strtoupper($vm['hostname']);
        if (!isset($iaasHostnames[$hn])) continue;
        enrichVmWithPricing($vm);
        if (($vm['price'] ?? 0) <= 0) continue;

        $cc = $vm['cost_center'] ?: '';
        $company = $ccToCompany[$cc] ?? 'Nicht zugeordnet';
        $price = (float) $vm['price'];

        $items[] = [
            'hostname' => $vm['hostname'],
            'vcpu' => $vm['vcpu'],
            'vram_mb' => $vm['vram_mb'],
            'storage_gb' => $vm['provisioned_storage_gb'],
            'points' => $vm['points'] ?? 0,
            'pricing_class' => $vm['pricing_class'] ?? '',
            'price' => $price,
            'cost_center' => $cc,
            'company' => $company,
        ];
        $totalPrice += $price;
    }

    return ['items' => $items, 'total_count' => count($items), 'total_price' => round($totalPrice, 2)];
}

/**
 * Collect license billing data.
 */
function collectLicenseData(\PDO $pdo, string $month): array
{
    $ccToCompany = [];
    $ccRows = $pdo->query("SELECT cc.name, co.name AS company_name FROM cost_center cc LEFT JOIN company co ON co.id = cc.company_id")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($ccRows as $r) $ccToCompany[$r['name']] = $r['company_name'] ?? '';

    $stmt = $pdo->prepare("
        SELECT eu.display_name, eu.user_principal_name, eu.cost_center, eu.company_name,
               ls.sku_part_number, ls.display_name AS license_name, ls.price
        FROM entra_license_assignment ela
        JOIN entra_user eu ON eu.id = ela.entra_user_id
        JOIN license_sku ls ON ls.id = ela.license_sku_id AND ls.is_active = TRUE
        WHERE ela.export_month = :month
    ");
    $stmt->execute(['month' => $month]);

    $items = [];
    $totalPrice = 0.0;
    $users = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $cc = $row['cost_center'] ?: '';
        $company = $ccToCompany[$cc] ?? 'Nicht zugeordnet';
        $price = (float) $row['price'];

        $items[] = [
            'user' => $row['display_name'],
            'upn' => $row['user_principal_name'],
            'license' => $row['license_name'],
            'sku' => $row['sku_part_number'],
            'price' => $price,
            'cost_center' => $cc,
            'company' => $company,
        ];
        $totalPrice += $price;
        $users[$row['user_principal_name']] = true;
    }

    return [
        'items' => $items,
        'total_assignments' => count($items),
        'total_users' => count($users),
        'total_price' => round($totalPrice, 2),
    ];
}

/**
 * Collect device billing data.
 */
function collectDeviceData(\PDO $pdo, string $month): array
{
    $ccToCompany = [];
    $ccRows = $pdo->query("SELECT cc.name, co.name AS company_name FROM cost_center cc LEFT JOIN company co ON co.id = cc.company_id")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($ccRows as $r) $ccToCompany[$r['name']] = $r['company_name'] ?? '';

    $stmt = $pdo->prepare("
        SELECT device_name, manufacturer, model, serial_number, user_display_name,
               device_category, device_price, cost_center, company_name
        FROM intune_device
        WHERE export_month = :month AND device_price > 0
    ");
    $stmt->execute(['month' => $month]);

    $items = [];
    $totalPrice = 0.0;
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $cc = $row['cost_center'] ?: '';
        $company = $ccToCompany[$cc] ?? 'Nicht zugeordnet';
        $price = (float) $row['device_price'];

        $items[] = [
            'device' => $row['device_name'],
            'user' => $row['user_display_name'],
            'manufacturer' => $row['manufacturer'],
            'model' => $row['model'],
            'category' => $row['device_category'],
            'price' => $price,
            'cost_center' => $cc,
            'company' => $company,
        ];
        $totalPrice += $price;
    }

    return ['items' => $items, 'total_count' => count($items), 'total_price' => round($totalPrice, 2)];
}

/**
 * List all snapshots.
 */
function listSnapshots(): array
{
    $pdo = getDb();
    try {
        return $pdo->query("
            SELECT id, snapshot_month, created_at, created_by, status, summary
            FROM billing_snapshot
            ORDER BY snapshot_month DESC
        ")->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
        return [];
    }
}

/**
 * Get a specific snapshot.
 */
function getSnapshot(int $id): ?array
{
    $pdo = getDb();
    $stmt = $pdo->prepare("SELECT * FROM billing_snapshot WHERE id = :id");
    $stmt->execute(['id' => $id]);
    return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
}

/**
 * Delete a snapshot (admin only).
 */
function deleteSnapshot(int $id, string $username): bool
{
    $pdo = getDb();
    $stmt = $pdo->prepare("DELETE FROM billing_snapshot WHERE id = :id");
    $stmt->execute(['id' => $id]);
    if ($stmt->rowCount() > 0) {
        AppLogger::warn('billing', "Snapshot #{$id} deleted", [], $username);
        return true;
    }
    return false;
}
