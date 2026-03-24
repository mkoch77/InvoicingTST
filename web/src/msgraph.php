<?php
/**
 * Microsoft Graph API client for Entra ID user & license sync.
 *
 * Required vault secrets:
 *   entra_tenant_id     – Azure AD / Entra Tenant ID
 *   entra_client_id     – App Registration Client ID
 *   entra_client_secret – App Registration Client Secret
 *
 * Required API permissions (Application, not Delegated):
 *   User.Read.All, Directory.Read.All
 */

require_once __DIR__ . '/vault.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';

class MsGraphClient
{
    private string $tenantId;
    private string $clientId;
    private string $clientSecret;
    private string $accessToken;

    public function __construct()
    {
        $this->tenantId     = getVaultSecret('entra_tenant_id') ?? '';
        $this->clientId     = getVaultSecret('entra_client_id') ?? '';
        $this->clientSecret = getVaultSecret('entra_client_secret') ?? '';

        if (!$this->tenantId || !$this->clientId || !$this->clientSecret) {
            throw new \RuntimeException(
                'Entra ID nicht konfiguriert. Bitte entra_tenant_id, entra_client_id und entra_client_secret im Vault hinterlegen.'
            );
        }

        $this->accessToken = $this->getAccessToken();
    }

    private function getAccessToken(): string
    {
        $url = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";

        $opts = [
            'http' => [
                'method'        => 'POST',
                'header'        => 'Content-Type: application/x-www-form-urlencoded',
                'content'       => http_build_query([
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope'         => 'https://graph.microsoft.com/.default',
                ]),
                'ignore_errors' => true,
                'timeout'       => 15,
            ],
        ];

        $ctx  = stream_context_create($opts);
        $resp = file_get_contents($url, false, $ctx);
        $data = json_decode($resp ?: '{}', true);

        if (empty($data['access_token'])) {
            $err = $data['error_description'] ?? $data['error'] ?? 'Unknown error';
            throw new \RuntimeException("Entra OAuth fehlgeschlagen: {$err}");
        }

        return $data['access_token'];
    }

    /**
     * GET request to Microsoft Graph API with pagination support.
     */
    private function graphGet(string $url): array
    {
        $allValues = [];

        while ($url) {
            $opts = [
                'http' => [
                    'method'        => 'GET',
                    'header'        => implode("\r\n", [
                        "Authorization: Bearer {$this->accessToken}",
                        'Accept: application/json',
                        'ConsistencyLevel: eventual',
                    ]),
                    'ignore_errors' => true,
                    'timeout'       => 30,
                ],
            ];

            $ctx  = stream_context_create($opts);
            $resp = file_get_contents($url, false, $ctx);

            $status = 0;
            if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $m)) {
                $status = (int) $m[0];
            }

            if ($status === 401) {
                throw new \RuntimeException('Microsoft Graph API: Zugriff verweigert (401)');
            }

            $data = json_decode($resp ?: '{}', true);

            if ($status >= 400) {
                $msg = $data['error']['message'] ?? "HTTP {$status}";
                throw new \RuntimeException("Microsoft Graph API error: {$msg}");
            }

            if (isset($data['value'])) {
                $allValues = array_merge($allValues, $data['value']);
            }

            $url = $data['@odata.nextLink'] ?? null;
        }

        return $allValues;
    }

    /**
     * Fetch all users with license assignments.
     */
    public function getUsers(): array
    {
        $select = 'id,displayName,userPrincipalName,assignedLicenses,streetAddress,city,department,companyName';
        return $this->graphGet(
            "https://graph.microsoft.com/v1.0/users?\$select={$select}&\$top=999"
        );
    }

    /**
     * Fetch subscribed SKUs (license types).
     */
    public function getSubscribedSkus(): array
    {
        return $this->graphGet(
            'https://graph.microsoft.com/v1.0/subscribedSkus'
        );
    }
}

/**
 * Sync Entra users and license assignments to the database.
 */
function syncEntraLicenses(string $username = 'system'): array
{
    $client = new MsGraphClient();
    $pdo = getDb();

    // Target license SKU part numbers
    $targetLicenses = [
        'SPE_E3', 'SPE_E5', 'INTUNE_A_D', 'PROJECT_PLAN3_DEPT',
        'PROJECTPREMIUM', 'PROJECTPROFESSIONAL', 'VISIOCLIENT',
        'PBI_PREMIUM_PER_USER', 'Microsoft_Teams_Rooms_Pro',
        'O365_w/o_Teams_Bundle_E3', 'M365_F1_COMM',
        'IDENTITY_THREAT_PROTECTION', 'Microsoft_Teams_EEA_New',
        'Microsoft_365_Copilot', 'AAD_PREMIUM_P2',
    ];

    // 1. Fetch subscribed SKUs and build map
    $skus = $client->getSubscribedSkus();
    $skuMap = []; // skuId => skuPartNumber

    foreach ($skus as $sku) {
        $partNumber = $sku['skuPartNumber'] ?? '';
        if (in_array($partNumber, $targetLicenses, true)) {
            $skuMap[$sku['skuId']] = $partNumber;

            // Update sku_id in DB (replace placeholder)
            $pdo->prepare("
                UPDATE license_sku SET sku_id = :real_id
                WHERE sku_part_number = :part AND sku_id LIKE 'placeholder_%'
            ")->execute([
                'real_id' => $sku['skuId'],
                'part'    => $partNumber,
            ]);
        }
    }

    AppLogger::info('license-sync', 'Fetched ' . count($skuMap) . ' relevant SKUs from Entra', [], $username);

    // Load license_sku DB map (sku_part_number => id)
    $dbSkuMap = [];
    $rows = $pdo->query("SELECT id, sku_part_number FROM license_sku")->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $dbSkuMap[$r['sku_part_number']] = (int) $r['id'];
    }

    // 2. Fetch all users
    $users = $client->getUsers();
    AppLogger::info('license-sync', 'Fetched ' . count($users) . ' users from Entra', [], $username);

    $currentMonth = date('Y-m');

    // Delete existing assignments for current month (full refresh)
    $pdo->prepare("DELETE FROM entra_license_assignment WHERE export_month = :month")
        ->execute(['month' => $currentMonth]);

    $userStmt = $pdo->prepare("
        INSERT INTO entra_user (entra_id, display_name, user_principal_name, department, street_address, city, company_name, updated_at)
        VALUES (:entra_id, :display_name, :upn, :dept, :street, :city, :company, NOW())
        ON CONFLICT (entra_id) DO UPDATE SET
            display_name = EXCLUDED.display_name,
            user_principal_name = EXCLUDED.user_principal_name,
            department = EXCLUDED.department,
            street_address = EXCLUDED.street_address,
            city = EXCLUDED.city,
            company_name = EXCLUDED.company_name,
            is_active = TRUE,
            updated_at = NOW()
        RETURNING id
    ");

    $assignStmt = $pdo->prepare("
        INSERT INTO entra_license_assignment (entra_user_id, license_sku_id, export_month)
        VALUES (:user_id, :sku_id, :month)
        ON CONFLICT DO NOTHING
    ");

    $userCount = 0;
    $assignCount = 0;

    foreach ($users as $user) {
        $assignedLicenses = $user['assignedLicenses'] ?? [];
        if (empty($assignedLicenses)) continue;

        // Check if user has any target license
        $relevantSkuIds = [];
        foreach ($assignedLicenses as $lic) {
            $skuId = $lic['skuId'] ?? '';
            if (isset($skuMap[$skuId])) {
                $partNumber = $skuMap[$skuId];
                if (isset($dbSkuMap[$partNumber])) {
                    $relevantSkuIds[] = $dbSkuMap[$partNumber];
                }
            }
        }

        if (empty($relevantSkuIds)) continue;

        // Upsert user
        $userStmt->execute([
            'entra_id'     => $user['id'] ?? '',
            'display_name' => $user['displayName'] ?? '',
            'upn'          => $user['userPrincipalName'] ?? '',
            'dept'         => $user['department'] ?? null,
            'street'       => $user['streetAddress'] ?? null,
            'city'         => $user['city'] ?? null,
            'company'      => $user['companyName'] ?? null,
        ]);
        $dbUserId = (int) $userStmt->fetchColumn();
        $userCount++;

        // Insert assignments
        foreach ($relevantSkuIds as $dbSkuId) {
            $assignStmt->execute([
                'user_id' => $dbUserId,
                'sku_id'  => $dbSkuId,
                'month'   => $currentMonth,
            ]);
            $assignCount++;
        }
    }

    // 3. Enrich users with CMDB data (cost center + company from CMDB2 User objects)
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

        // Load valid cost center numbers from DB
        $validCostCenters = [];
        $ccRows = $pdo->query("SELECT name FROM cost_center")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ccRows as $ccName) {
            $validCostCenters[$ccName] = true;
        }

        // Fetch all active CMDB users
        $cmdbUsers = $cmdb->searchAllObjects('objectType = "User" AND objectSchemaId = 8 AND Status = "Active"');
        AppLogger::info('license-sync', 'Fetched ' . count($cmdbUsers) . ' CMDB User objects', [], $username);

        // Build email → (cost_center, company) map
        $cmdbMap = [];
        foreach ($cmdbUsers as $obj) {
            $email = strtolower($getAttr($obj['attributes'] ?? [], $CMDB_USER_EMAIL));
            $ccHR = $getAttr($obj['attributes'] ?? [], $CMDB_USER_COST_CENTER_HR);
            $ccNormal = $getAttr($obj['attributes'] ?? [], $CMDB_USER_COST_CENTER);
            $company = $getAttr($obj['attributes'] ?? [], $CMDB_USER_COMPANY);

            // Priorität: CostCenterFromHR wenn es eine gültige Zahl ist die in cost_center existiert
            $cc = '';
            if ($ccHR && preg_match('/^\d+$/', $ccHR) && isset($validCostCenters[$ccHR])) {
                $cc = $ccHR;
            } elseif ($ccNormal && preg_match('/^\d+$/', $ccNormal) && isset($validCostCenters[$ccNormal])) {
                $cc = $ccNormal;
            } elseif ($ccNormal) {
                $cc = $ccNormal; // Fallback: use as-is even if not in cost_center table
            }

            if ($email) {
                $cmdbMap[$email] = ['cost_center' => $cc, 'company' => $company];
            }
        }

        // Update entra_user with CMDB data
        $enrichStmt = $pdo->prepare("
            UPDATE entra_user SET cost_center = :cc, company_name = COALESCE(NULLIF(:company, ''), company_name)
            WHERE LOWER(user_principal_name) = :upn
        ");

        foreach ($cmdbMap as $email => $data) {
            if ($data['cost_center']) {
                $enrichStmt->execute([
                    'cc'      => $data['cost_center'],
                    'company' => $data['company'],
                    'upn'     => $email,
                ]);
                if ($enrichStmt->rowCount() > 0) $cmdbEnriched++;
            }
        }

        AppLogger::info('license-sync', "CMDB enrichment: {$cmdbEnriched} users updated with cost center", [], $username);
    } catch (\Exception $e) {
        AppLogger::warn('license-sync', "CMDB enrichment failed (non-critical): {$e->getMessage()}", [], $username);
    }

    AppLogger::info('license-sync', "Sync complete: {$userCount} users, {$assignCount} assignments, {$cmdbEnriched} CMDB-enriched for {$currentMonth}", [], $username);

    return [
        'users'       => $userCount,
        'assignments' => $assignCount,
        'cmdb'        => $cmdbEnriched,
        'month'       => $currentMonth,
    ];
}
