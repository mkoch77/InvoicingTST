<?php
/**
 * Sync network devices from Netbox → CMDB2 (Jira Assets).
 *
 * Creates/updates NetworkDevice objects (type 350) and
 * ensures NetworkDeviceModell (type 351) references exist.
 *
 * CMDB Attribute IDs for NetworkDevice (350):
 *   [1964] Name
 *   [1967] SerialNumber
 *   [1968] Status           (type=7, select/status)
 *   [1969] Notice
 *   [2789] NetworkDeviceType (text: Switch/AccessPoint/Router)
 *   [2791] IP
 *   [2151] Modell            (ref → 234 HardwareModell, but we use NetworkDeviceModell)
 *
 * CMDB Attribute IDs for NetworkDeviceModell (351):
 *   [2102] Name
 *   [2105] Manufacturer     (ref → 224 Provider)
 *   [2114] Status
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jira_assets.php';
require_once __DIR__ . '/logger.php';

// CMDB Object Type IDs
const CMDB_NETWORK_DEVICE_TYPE = 350;
const CMDB_NETWORK_DEVICE_MODELL_TYPE = 351;

// NetworkDevice attribute IDs
const ATTR_ND_NAME              = 1964;
const ATTR_ND_SERIAL            = 1967;
const ATTR_ND_STATUS            = 1968;
const ATTR_ND_NOTICE            = 1969;
const ATTR_ND_NETWORK_TYPE      = 2789;
const ATTR_ND_IP                = 2791;

// NetworkDeviceModell attribute IDs
const ATTR_NDM_NAME             = 2102;
const ATTR_NDM_STATUS           = 2114;

/**
 * Sync Netbox devices to CMDB.
 *
 * @param int $limit Max devices to sync (0 = all)
 * @param string $username For logging
 * @return array Summary
 */
function syncNetboxToCmdb(int $limit = 0, string $username = 'system'): array
{
    $pdo = getDb();
    $client = new JiraAssetsClient();

    $currentMonth = date('Y-m');
    AppLogger::info('netbox-cmdb-sync', "Starting Netbox→CMDB sync (limit={$limit})", [], $username);

    // 1. Load existing CMDB NetworkDevice objects by Name for dedup
    $existingDevices = [];
    try {
        $cmdbDevices = $client->searchAllObjects(
            'objectType = "NetworkDevice" AND objectSchemaId = 8'
        );
        foreach ($cmdbDevices as $obj) {
            $name = JiraAssetsClient::getAttributeValue($obj, 'Name');
            if ($name) {
                $existingDevices[strtoupper($name)] = (int) $obj['id'];
            }
        }
    } catch (\Exception $e) {
        AppLogger::warn('netbox-cmdb-sync', 'Could not load existing CMDB devices: ' . $e->getMessage(), [], $username);
    }
    AppLogger::info('netbox-cmdb-sync', count($existingDevices) . ' existing CMDB NetworkDevice objects found', [], $username);

    // 2. Load existing NetworkDeviceModell objects for model matching
    $modellMap = []; // lowercase model name → CMDB object key (e.g. "CMDB2-50826")
    try {
        $cmdbModels = $client->searchAllObjects(
            'objectType = "NetworkDeviceModell" AND objectSchemaId = 8'
        );
        foreach ($cmdbModels as $obj) {
            $name = JiraAssetsClient::getAttributeValue($obj, 'Name');
            if ($name) {
                $modellMap[strtoupper($name)] = $obj['objectKey'] ?? '';
            }
        }
    } catch (\Exception $e) {
        AppLogger::warn('netbox-cmdb-sync', 'Could not load CMDB models: ' . $e->getMessage(), [], $username);
    }
    AppLogger::info('netbox-cmdb-sync', count($modellMap) . ' CMDB NetworkDeviceModell objects found', [], $username);

    // 3. Load Netbox devices from DB
    $stmt = $pdo->prepare("
        SELECT name, device_role, manufacturer, model, serial_number,
               site, location, rack, status, primary_ip, tenant, category
        FROM netbox_device
        WHERE export_month = :month
        ORDER BY name
    ");
    $stmt->execute(['month' => $currentMonth]);
    $netboxDevices = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    if ($limit > 0) {
        $netboxDevices = array_slice($netboxDevices, 0, $limit);
    }

    $created = 0;
    $updated = 0;
    $errors = 0;
    $modelsCreated = 0;

    foreach ($netboxDevices as $nb) {
        $name = $nb['name'] ?? '';
        if (!$name) continue;

        // Map category to NetworkDeviceType text
        $typeMap = ['switch' => 'Switch', 'accesspoint' => 'AccessPoint', 'router' => 'Router'];
        $networkType = $typeMap[$nb['category']] ?? $nb['category'];

        // Build model display name: "Manufacturer Model"
        $modelName = trim(($nb['manufacturer'] ?? '') . ' ' . ($nb['model'] ?? ''));

        // Build attributes
        $attrs = [
            ATTR_ND_NAME         => $name,
            ATTR_ND_SERIAL       => $nb['serial_number'] ?? '',
            ATTR_ND_NETWORK_TYPE => $networkType,
            ATTR_ND_IP           => $nb['primary_ip'] ?? '',
            ATTR_ND_NOTICE       => "Site: {$nb['site']}, Location: {$nb['location']}, Rack: {$nb['rack']}, Role: {$nb['device_role']}",
        ];

        try {
            $existingId = $existingDevices[strtoupper($name)] ?? null;

            if ($existingId) {
                // Update existing
                $client->updateObject($existingId, CMDB_NETWORK_DEVICE_TYPE, $attrs);
                $updated++;
            } else {
                // Create new
                $result = $client->createObject(CMDB_NETWORK_DEVICE_TYPE, $attrs);
                $created++;
                // Track for dedup within same run
                $existingDevices[strtoupper($name)] = (int) ($result['id'] ?? 0);
            }
        } catch (\Exception $e) {
            $errors++;
            if ($errors <= 5) {
                AppLogger::warn('netbox-cmdb-sync', "Failed to sync '{$name}': " . $e->getMessage(), [], $username);
            }
        }
    }

    $msg = "Netbox→CMDB sync complete: {$created} created, {$updated} updated, {$errors} errors (of " . count($netboxDevices) . " devices)";
    AppLogger::info('netbox-cmdb-sync', $msg, [], $username);

    return [
        'created' => $created,
        'updated' => $updated,
        'errors'  => $errors,
        'total'   => count($netboxDevices),
        'models_created' => $modelsCreated,
    ];
}
