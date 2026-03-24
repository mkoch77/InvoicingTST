<?php
/**
 * Intune device sync via Microsoft Graph API.
 *
 * Uses the same Entra credentials as the license sync (entra_tenant_id, entra_client_id, entra_client_secret).
 * Required API permissions (Application): DeviceManagementManagedDevices.Read.All
 */

require_once __DIR__ . '/msgraph.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/device_pricing.php';

function syncIntuneDevices(string $username = 'system'): array
{
    $client = new MsGraphClient();
    $pdo = getDb();

    // Fetch Windows devices from Intune (beta endpoint)
    $devices = $client->graphGetPublic(
        'https://graph.microsoft.com/beta/deviceManagement/managedDevices?$filter=operatingSystem%20eq%20%27Windows%27&$top=999'
    );

    AppLogger::info('device-sync', 'Fetched ' . count($devices) . ' Windows devices from Intune', [], $username);

    // Filter: only devices with known compliance state
    $devices = array_filter($devices, fn($d) => ($d['complianceState'] ?? 'unknown') !== 'unknown');
    AppLogger::info('device-sync', count($devices) . ' devices after compliance filter', [], $username);

    $currentMonth = date('Y-m');

    // Delete existing devices for current month (full refresh)
    $pdo->prepare("DELETE FROM intune_device WHERE export_month = :month")
        ->execute(['month' => $currentMonth]);

    $stmt = $pdo->prepare("
        INSERT INTO intune_device (device_id, azure_ad_device_id, device_name, serial_number,
            manufacturer, model, user_display_name, user_principal_name, compliance_state,
            last_sync, export_month, device_category, device_price, updated_at)
        VALUES (:device_id, :azure_id, :name, :serial, :manufacturer, :model,
            :user_name, :upn, :compliance, :last_sync, :month, :category, :price, NOW())
        ON CONFLICT (device_id) DO UPDATE SET
            azure_ad_device_id = EXCLUDED.azure_ad_device_id,
            device_name = EXCLUDED.device_name,
            serial_number = EXCLUDED.serial_number,
            manufacturer = EXCLUDED.manufacturer,
            model = EXCLUDED.model,
            user_display_name = EXCLUDED.user_display_name,
            user_principal_name = EXCLUDED.user_principal_name,
            compliance_state = EXCLUDED.compliance_state,
            last_sync = EXCLUDED.last_sync,
            export_month = EXCLUDED.export_month,
            device_category = EXCLUDED.device_category,
            device_price = EXCLUDED.device_price,
            updated_at = NOW()
    ");

    $tiers = getDevicePricingTiers();
    $count = 0;
    foreach ($devices as $d) {
        $pricing = classifyDevice($d['manufacturer'] ?? '', $d['model'] ?? '', $tiers);
        $stmt->execute([
            'device_id'    => $d['id'] ?? '',
            'azure_id'     => $d['azureADDeviceId'] ?? null,
            'name'         => $d['deviceName'] ?? '',
            'serial'       => $d['serialNumber'] ?? null,
            'manufacturer' => $d['manufacturer'] ?? null,
            'model'        => $d['model'] ?? null,
            'user_name'    => $d['userDisplayName'] ?? null,
            'upn'          => $d['userPrincipalName'] ?? null,
            'compliance'   => $d['complianceState'] ?? null,
            'last_sync'    => $d['lastSyncDateTime'] ?? null,
            'month'        => $currentMonth,
            'category'     => $pricing['category'] ?? null,
            'price'        => $pricing['price'] ?? 0,
        ]);
        $count++;
    }

    // Enrich with CMDB data (cost center + company)
    $cmdbEnriched = 0;
    try {
        require_once __DIR__ . '/jira_assets.php';
        $cmdb = new JiraAssetsClient();

        $CMDB_USER_EMAIL = '2050';
        $CMDB_USER_COST_CENTER = '2059';
        $CMDB_USER_COST_CENTER_HR = '2275';
        $CMDB_USER_COMPANY = '2054';

        $getAttr = function(array $attrs, string $attrId): string {
            foreach ($attrs as $a) {
                if ((string)($a['objectTypeAttributeId'] ?? '') === $attrId) {
                    $values = array_unique(array_filter(array_map(
                        fn($v) => $v['displayValue'] ?? $v['value'] ?? '',
                        $a['objectAttributeValues'] ?? []
                    )));
                    return implode(', ', $values);
                }
            }
            return '';
        };

        // Load valid cost centers
        $validCostCenters = [];
        $ccRows = $pdo->query("SELECT name FROM cost_center")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ccRows as $ccName) {
            $validCostCenters[$ccName] = true;
        }

        // Fetch CMDB users
        $cmdbUsers = $cmdb->searchAllObjects('objectType = "User" AND objectSchemaId = 8 AND Status = "Active"');

        $cmdbMap = [];
        foreach ($cmdbUsers as $obj) {
            $email = strtolower($getAttr($obj['attributes'] ?? [], $CMDB_USER_EMAIL));
            $ccHR = $getAttr($obj['attributes'] ?? [], $CMDB_USER_COST_CENTER_HR);
            $ccNormal = $getAttr($obj['attributes'] ?? [], $CMDB_USER_COST_CENTER);
            $company = $getAttr($obj['attributes'] ?? [], $CMDB_USER_COMPANY);

            $cc = '';
            if ($ccHR && preg_match('/^\d+$/', $ccHR) && isset($validCostCenters[$ccHR])) {
                $cc = $ccHR;
            } elseif ($ccNormal && preg_match('/^\d+$/', $ccNormal) && isset($validCostCenters[$ccNormal])) {
                $cc = $ccNormal;
            } elseif ($ccNormal) {
                $cc = $ccNormal;
            }

            if ($email) {
                $cmdbMap[$email] = ['cost_center' => $cc, 'company' => $company];
            }
        }

        // Update devices with CMDB data
        $enrichStmt = $pdo->prepare("
            UPDATE intune_device SET cost_center = :cc, company_name = :company
            WHERE LOWER(user_principal_name) = :upn AND export_month = :month
        ");

        foreach ($cmdbMap as $email => $data) {
            if ($data['cost_center']) {
                $enrichStmt->execute([
                    'cc'      => $data['cost_center'],
                    'company' => $data['company'],
                    'upn'     => $email,
                    'month'   => $currentMonth,
                ]);
                $cmdbEnriched += $enrichStmt->rowCount();
            }
        }

        AppLogger::info('device-sync', "CMDB enrichment: {$cmdbEnriched} devices updated", [], $username);
    } catch (\Exception $e) {
        AppLogger::warn('device-sync', "CMDB enrichment failed: {$e->getMessage()}", [], $username);
    }

    AppLogger::info('device-sync', "Sync complete: {$count} devices, {$cmdbEnriched} CMDB-enriched ({$currentMonth})", [], $username);

    return ['devices' => $count, 'cmdb' => $cmdbEnriched, 'month' => $currentMonth];
}
