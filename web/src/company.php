<?php
/**
 * Company structure sync from Jira Assets CMDB.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jira_assets.php';
require_once __DIR__ . '/logger.php';

function syncCompanyStructure(string $username = 'system'): array
{
    $client = new JiraAssetsClient();
    $pdo = getDb();

    // Attr IDs for TST
    $TST_NAME = '1976';
    $TST_LOCATION = '2200';
    $TST_STATUS = '1979';

    // Attr IDs for CostCenter
    $CC_NAME = '2022';
    $CC_BEARER = '2025';
    $CC_ADDRESS = '2027';
    $CC_COMPANY = '2028';
    $CC_STATUS = '2236';
    $CC_CUSTOMER = '2745';

    $getAttr = function(array $attrs, string $attrId): string {
        foreach ($attrs as $a) {
            if ((string)($a['objectTypeAttributeId'] ?? '') === $attrId) {
                return implode(', ', array_map(
                    fn($v) => $v['displayValue'] ?? $v['value'] ?? '',
                    $a['objectAttributeValues'] ?? []
                ));
            }
        }
        return '';
    };

    // 1. Fetch all TST companies
    $tstObjects = $client->searchAllObjects('objectType = "TST" AND objectSchemaId = 8');
    AppLogger::info('company-sync', "Fetched " . count($tstObjects) . " TST objects", [], $username);

    $companyStmt = $pdo->prepare("
        INSERT INTO company (cmdb_key, name, location, status)
        VALUES (:cmdb_key, :name, :location, :status)
        ON CONFLICT (cmdb_key) DO UPDATE SET
            name = EXCLUDED.name,
            location = EXCLUDED.location,
            status = EXCLUDED.status,
            updated_at = NOW()
        WHERE company.name != EXCLUDED.name
           OR company.location != EXCLUDED.location
           OR company.status != EXCLUDED.status
        RETURNING id
    ");

    $companyMap = [];
    foreach ($tstObjects as $obj) {
        $key = $obj['objectKey'] ?? '';
        $name = $getAttr($obj['attributes'] ?? [], $TST_NAME);
        $location = $getAttr($obj['attributes'] ?? [], $TST_LOCATION);
        $status = $getAttr($obj['attributes'] ?? [], $TST_STATUS);

        if (!$name) continue;

        $companyStmt->execute([
            'cmdb_key' => $key,
            'name' => $name,
            'location' => $location,
            'status' => $status ?: 'Active',
        ]);

        $id = $companyStmt->fetchColumn();
        if (!$id) {
            $id = $pdo->query("SELECT id FROM company WHERE cmdb_key = " . $pdo->quote($key))->fetchColumn();
        }
        $companyMap[$name] = $id;
    }

    // 2. Fetch all CostCenters
    $ccObjects = $client->searchAllObjects('objectType = "CostCenter" AND objectSchemaId = 8');
    AppLogger::info('company-sync', "Fetched " . count($ccObjects) . " CostCenter objects", [], $username);

    $ccStmt = $pdo->prepare("
        INSERT INTO cost_center (cmdb_key, name, cost_bearer, address, customer, status, company_id)
        VALUES (:cmdb_key, :name, :cost_bearer, :address, :customer, :status, :company_id)
        ON CONFLICT (cmdb_key) DO UPDATE SET
            name = EXCLUDED.name,
            cost_bearer = EXCLUDED.cost_bearer,
            address = EXCLUDED.address,
            customer = EXCLUDED.customer,
            status = EXCLUDED.status,
            company_id = EXCLUDED.company_id,
            updated_at = NOW()
        WHERE cost_center.name != EXCLUDED.name
           OR cost_center.cost_bearer != EXCLUDED.cost_bearer
           OR cost_center.address != EXCLUDED.address
           OR COALESCE(cost_center.customer,'') != COALESCE(EXCLUDED.customer,'')
           OR cost_center.status != EXCLUDED.status
           OR COALESCE(cost_center.company_id,0) != COALESCE(EXCLUDED.company_id,0)
    ");

    $synced = 0;
    foreach ($ccObjects as $obj) {
        $key = $obj['objectKey'] ?? '';
        $name = $getAttr($obj['attributes'] ?? [], $CC_NAME);
        $bearer = $getAttr($obj['attributes'] ?? [], $CC_BEARER);
        $address = $getAttr($obj['attributes'] ?? [], $CC_ADDRESS);
        $companyName = $getAttr($obj['attributes'] ?? [], $CC_COMPANY);
        $status = $getAttr($obj['attributes'] ?? [], $CC_STATUS);
        $customer = $getAttr($obj['attributes'] ?? [], $CC_CUSTOMER);

        if (!$name) continue;

        $companyId = $companyMap[$companyName] ?? null;

        $ccStmt->execute([
            'cmdb_key' => $key,
            'name' => $name,
            'cost_bearer' => $bearer,
            'address' => $address,
            'customer' => $customer ?: null,
            'status' => $status ?: 'Active',
            'company_id' => $companyId,
        ]);
        $synced++;
    }

    // 3. Sync Server → IT-Service mapping (with CMDB customer)
    $SERVER_NAME = '1964';
    $SERVER_IT_SERVICE = '2613';
    $SERVER_CUSTOMER = '2214';

    $serverObjects = $client->searchAllObjects('objectType = "Server" AND objectSchemaId = 8 AND Status = "Active"');
    AppLogger::info('company-sync', "Fetched " . count($serverObjects) . " active Server objects", [], $username);

    $ssmStmt = $pdo->prepare("
        INSERT INTO server_service_mapping (hostname, it_service, cmdb_customer, cmdb_key, updated_at)
        VALUES (:hostname, :it_service, :cmdb_customer, :cmdb_key, NOW())
        ON CONFLICT (hostname) DO UPDATE SET
            it_service = EXCLUDED.it_service,
            cmdb_customer = EXCLUDED.cmdb_customer,
            cmdb_key = EXCLUDED.cmdb_key,
            updated_at = NOW()
    ");

    $serverSynced = 0;
    foreach ($serverObjects as $obj) {
        $key = $obj['objectKey'] ?? '';
        $name = $getAttr($obj['attributes'] ?? [], $SERVER_NAME);
        $itService = $getAttr($obj['attributes'] ?? [], $SERVER_IT_SERVICE);
        $cmdbCustomer = $getAttr($obj['attributes'] ?? [], $SERVER_CUSTOMER) ?: null;

        if (!$name || !$itService) continue;

        // Extract hostname (first part before domain)
        $hostname = strtoupper(explode('.', $name)[0]);

        $ssmStmt->execute([
            'hostname' => $hostname,
            'it_service' => $itService,
            'cmdb_customer' => $cmdbCustomer,
            'cmdb_key' => $key,
        ]);
        $serverSynced++;
    }

    AppLogger::info('company-sync', "Server service mapping: $serverSynced servers synced", [], $username);

    $companyCount = count($tstObjects);
    AppLogger::info('company-sync', "Sync complete: $companyCount companies, $synced cost centers, $serverSynced server mappings", [], $username);

    return ['companies' => $companyCount, 'cost_centers' => $synced, 'servers' => $serverSynced];
}
