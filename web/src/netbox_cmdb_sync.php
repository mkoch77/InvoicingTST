<?php
/**
 * Sync network devices from Netbox → CMDB2 (Jira Assets).
 *
 * Creates/updates NetworkDevice objects (type 350).
 *
 * CMDB Required Attributes for NetworkDevice (350):
 *   [1964] Name              (text)
 *   [1967] SerialNumber      (text, required)
 *   [1968] Status            (status, required) = "Active"
 *   [2107] CostCenter        (ref → 222, required) - default placeholder
 *   [2151] Modell            (ref → 234/351, required) - matched from Netbox model
 *   [2161] LeasingByITFromVendor (text, required) = "false"
 *   [2789] NetworkDeviceType (text) = Switch/AccessPoint/Router
 *   [1969] Notice            (text) - site, location, rack info
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jira_assets.php';
require_once __DIR__ . '/logger.php';

const CMDB_NETWORK_DEVICE_TYPE = 350;

// Required attribute IDs
const ATTR_ND_NAME         = 1964;
const ATTR_ND_SERIAL       = 1967;
const ATTR_ND_STATUS       = 1968;
const ATTR_ND_NOTICE       = 1969;
const ATTR_ND_COSTCENTER   = 2107;
const ATTR_ND_MODELL       = 2151;
const ATTR_ND_LEASING      = 2161;
const ATTR_ND_NETWORK_TYPE = 2789;

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
    AppLogger::info('netbox-cmdb-sync', count($existingDevices) . ' existing CMDB NetworkDevice objects', [], $username);

    // 2. Load NetworkDeviceModell objects for model matching
    $modellMap = []; // uppercase "Manufacturer Model" → CMDB object key
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
    AppLogger::info('netbox-cmdb-sync', count($modellMap) . ' CMDB NetworkDeviceModell objects', [], $username);

    // 3. Find a default CostCenter for required field (first available)
    $defaultCostCenter = null;
    try {
        $cc = $client->searchObjects('objectType = "CostCenter" AND objectSchemaId = 8 AND Name = "999130"', 0, 1);
        $entries = $cc['values'] ?? $cc['objectEntries'] ?? [];
        if (!empty($entries)) {
            $defaultCostCenter = $entries[0]['objectKey'] ?? null;
        }
        if (!$defaultCostCenter) {
            $cc = $client->searchObjects('objectType = "CostCenter" AND objectSchemaId = 8', 0, 1);
            $entries = $cc['values'] ?? $cc['objectEntries'] ?? [];
            if (!empty($entries)) {
                $defaultCostCenter = $entries[0]['objectKey'] ?? null;
            }
        }
    } catch (\Exception $e) {
        // ignore
    }
    if (!$defaultCostCenter) {
        $defaultCostCenter = 'CMDB2-26500'; // Fallback: CostCenter "100"
        AppLogger::warn('netbox-cmdb-sync', 'CostCenter lookup failed, using fallback: ' . $defaultCostCenter, [], $username);
    }

    // 4. Load Netbox devices from DB
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

    foreach ($netboxDevices as $nb) {
        $name = $nb['name'] ?? '';
        if (!$name) continue;

        $typeMap = ['switch' => 'Switch', 'accesspoint' => 'AccessPoint', 'router' => 'Router'];
        $networkType = $typeMap[$nb['category']] ?? $nb['category'];

        // Match model: try "Manufacturer Model" first, then just "Model"
        $modelFull = trim(($nb['manufacturer'] ?? '') . ' ' . ($nb['model'] ?? ''));
        $modelShort = trim($nb['model'] ?? '');
        $modellKey = $modellMap[strtoupper($modelFull)]
            ?? $modellMap[strtoupper($modelShort)]
            ?? null;

        // Fallback: use placeholder model CMDB2-50794 ("0") if no match
        if (!$modellKey) {
            $modellKey = !empty($modellMap) ? ($modellMap['0'] ?? reset($modellMap)) : 'CMDB2-50794';
        }

        $serial = $nb['serial_number'] ?? '';
        if (!$serial) $serial = 'NB-' . $name; // Unique fallback per device

        $notice = implode(', ', array_filter([
            $nb['site'] ? "Site: {$nb['site']}" : '',
            $nb['location'] ? "Location: {$nb['location']}" : '',
            $nb['rack'] ? "Rack: {$nb['rack']}" : '',
            $nb['device_role'] ? "Role: {$nb['device_role']}" : '',
            $nb['primary_ip'] ? "IP: {$nb['primary_ip']}" : '',
        ]));

        $attrs = [
            ATTR_ND_NAME         => $name,
            ATTR_ND_SERIAL       => $serial,
            ATTR_ND_STATUS       => 'Active',
            ATTR_ND_COSTCENTER   => $defaultCostCenter,
            ATTR_ND_MODELL       => $modellKey,
            ATTR_ND_LEASING      => 'false',
            ATTR_ND_NETWORK_TYPE => $networkType,
            ATTR_ND_NOTICE       => $notice,
        ];

        try {
            $existingId = $existingDevices[strtoupper($name)] ?? null;

            if ($existingId) {
                // Update — don't overwrite CostCenter if already set
                unset($attrs[ATTR_ND_COSTCENTER]);
                unset($attrs[ATTR_ND_STATUS]);
                unset($attrs[ATTR_ND_LEASING]);
                $client->updateObject($existingId, CMDB_NETWORK_DEVICE_TYPE, $attrs);
                $updated++;
            } else {
                $result = $client->createObject(CMDB_NETWORK_DEVICE_TYPE, $attrs);
                $created++;
                $existingDevices[strtoupper($name)] = (int) ($result['id'] ?? 0);
            }
        } catch (\Exception $e) {
            $errMsg = $e->getMessage();
            // If duplicate serial/name, try to find and update existing
            if (str_contains($errMsg, 'duplicated')) {
                try {
                    // Try by name first, then by serial number
                    $found = [];
                    $search = $client->searchObjects("objectType = \"NetworkDevice\" AND objectSchemaId = 8 AND Name = \"{$name}\"", 0, 1);
                    $found = $search['values'] ?? $search['objectEntries'] ?? [];

                    if (empty($found) && $serial) {
                        $escapedSerial = str_replace('"', '\\"', $serial);
                        $search = $client->searchObjects("objectType = \"NetworkDevice\" AND objectSchemaId = 8 AND SerialNumber = \"{$escapedSerial}\"", 0, 1);
                        $found = $search['values'] ?? $search['objectEntries'] ?? [];
                    }

                    if (!empty($found)) {
                        $existId = (int) $found[0]['id'];
                        unset($attrs[ATTR_ND_COSTCENTER], $attrs[ATTR_ND_STATUS], $attrs[ATTR_ND_LEASING], $attrs[ATTR_ND_SERIAL]);
                        $client->updateObject($existId, CMDB_NETWORK_DEVICE_TYPE, $attrs);
                        $updated++;
                        $existingDevices[strtoupper($name)] = $existId;
                        continue;
                    }
                } catch (\Exception $e2) {
                    // fall through to error
                }
            }
            $errors++;
            if ($errors <= 5) {
                AppLogger::warn('netbox-cmdb-sync', "Failed '{$name}': " . $errMsg, [], $username);
            }
        }
    }

    // Clean up test device if it exists
    $testId = $existingDevices['TEST-DEVICE-001'] ?? null;
    if ($testId) {
        try {
            // Can't delete via API easily, just log it
            AppLogger::info('netbox-cmdb-sync', "Note: TEST-DEVICE-001 (ID {$testId}) should be manually removed from CMDB", [], $username);
        } catch (\Exception $e) {}
    }

    $msg = "Netbox→CMDB: {$created} erstellt, {$updated} aktualisiert, {$errors} Fehler (von " . count($netboxDevices) . ")";
    AppLogger::info('netbox-cmdb-sync', $msg, [], $username);

    return [
        'created' => $created,
        'updated' => $updated,
        'errors'  => $errors,
        'total'   => count($netboxDevices),
    ];
}
