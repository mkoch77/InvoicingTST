<?php
/**
 * Netbox API client for syncing network devices (switches, access points).
 *
 * Required vault secrets:
 *   netbox_url       – Netbox instance URL (e.g. https://netbox.example.com)
 *   netbox_api_token – API token for authentication
 */

require_once __DIR__ . '/vault.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';

class NetboxClient
{
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim(getVaultSecret('netbox_url') ?? '', '/');
        $this->token   = getVaultSecret('netbox_api_token') ?? '';

        if (!$this->baseUrl || !$this->token) {
            throw new \RuntimeException('Netbox nicht konfiguriert. Bitte netbox_url und netbox_api_token im Vault hinterlegen.');
        }
    }

    /**
     * GET request with pagination support.
     */
    public function getAll(string $endpoint, array $params = []): array
    {
        $allResults = [];
        $url = $this->baseUrl . '/api/' . ltrim($endpoint, '/');
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        while ($url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Token {$this->token}",
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            $resp = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new \RuntimeException("Netbox connection error: {$curlError}");
            }

            if ($status >= 400) {
                $data = json_decode($resp ?: '{}', true);
                $msg = $data['detail'] ?? "HTTP {$status}";
                throw new \RuntimeException("Netbox API error: {$msg}");
            }

            $data = json_decode($resp ?: '{}', true);

            if (isset($data['results'])) {
                $allResults = array_merge($allResults, $data['results']);
            }

            $url = $data['next'] ?? null;
        }

        return $allResults;
    }
}

/**
 * Sync switches and access points from Netbox.
 *
 * Switches: role contains "switch" (case-insensitive)
 * Access Points: role contains "access" or "wireless" or "ap" (case-insensitive)
 */
function syncNetboxDevices(string $username = 'system'): array
{
    $client = new NetboxClient();
    $pdo = getDb();
    $currentMonth = date('Y-m');

    // Delete existing entries for current month (full refresh)
    $pdo->prepare("DELETE FROM netbox_device WHERE export_month = :month")
        ->execute(['month' => $currentMonth]);

    $stmt = $pdo->prepare("
        INSERT INTO netbox_device (netbox_id, name, device_type, device_role, manufacturer, model,
            serial_number, asset_tag, site, location, rack, status, primary_ip, tenant,
            category, description, export_month, updated_at)
        VALUES (:netbox_id, :name, :device_type, :device_role, :manufacturer, :model,
            :serial, :asset_tag, :site, :location, :rack, :status, :primary_ip, :tenant,
            :category, :description, :month, NOW())
        ON CONFLICT (netbox_id) DO UPDATE SET
            name = EXCLUDED.name,
            device_type = EXCLUDED.device_type,
            device_role = EXCLUDED.device_role,
            manufacturer = EXCLUDED.manufacturer,
            model = EXCLUDED.model,
            serial_number = EXCLUDED.serial_number,
            asset_tag = EXCLUDED.asset_tag,
            site = EXCLUDED.site,
            location = EXCLUDED.location,
            rack = EXCLUDED.rack,
            status = EXCLUDED.status,
            primary_ip = EXCLUDED.primary_ip,
            tenant = EXCLUDED.tenant,
            category = EXCLUDED.category,
            description = EXCLUDED.description,
            export_month = EXCLUDED.export_month,
            updated_at = NOW()
    ");

    // Fetch all devices from Netbox
    $devices = $client->getAll('dcim/devices/', ['limit' => 1000, 'status' => 'active']);
    AppLogger::info('netbox-sync', 'Fetched ' . count($devices) . ' active devices from Netbox', [], $username);

    $switchCount = 0;
    $apCount = 0;
    $routerCount = 0;
    $skipped = 0;

    foreach ($devices as $d) {
        $role = strtolower($d['role']['name'] ?? $d['device_role']['name'] ?? '');
        $roleName = $d['role']['name'] ?? $d['device_role']['name'] ?? '';

        // Classify: switch, access point, or router
        $category = null;
        $switchRoles = ['switch-ethernet', 'switch-fibrechannel', 'leaf', 'spine', 'border-leaf'];
        $apRoles = ['accesspoint', 'wlc'];
        $routerRoles = ['router-primary', 'router-secondary'];
        if (in_array($role, $switchRoles) || str_contains($role, 'switch') || str_contains($role, 'leaf') || str_contains($role, 'spine')) {
            $category = 'switch';
        } elseif (in_array($role, $apRoles) || str_contains($role, 'access') || str_contains($role, 'wireless') ||
                  str_contains($role, 'wifi') || str_contains($role, 'wlc')) {
            $category = 'accesspoint';
        } elseif (in_array($role, $routerRoles) || str_contains($role, 'router')) {
            $category = 'router';
        }

        if (!$category) {
            $skipped++;
            continue;
        }

        // Extract nested values safely
        $deviceType = $d['device_type']['display'] ?? $d['device_type']['model'] ?? '';
        $manufacturer = $d['device_type']['manufacturer']['name'] ?? $d['manufacturer']['name'] ?? '';
        $model = $d['device_type']['model'] ?? '';
        $site = $d['site']['name'] ?? '';
        $location = $d['location']['name'] ?? $d['location']['display'] ?? '';
        $rack = $d['rack']['name'] ?? '';
        $primaryIp = $d['primary_ip']['address'] ?? $d['primary_ip4']['address'] ?? '';
        $tenant = $d['tenant']['name'] ?? '';

        $stmt->execute([
            'netbox_id'   => (int) $d['id'],
            'name'        => $d['name'] ?? $d['display'] ?? '',
            'device_type' => $deviceType,
            'device_role' => $roleName,
            'manufacturer'=> $manufacturer,
            'model'       => $model,
            'serial'      => $d['serial'] ?? null,
            'asset_tag'   => $d['asset_tag'] ?? null,
            'site'        => $site,
            'location'    => $location,
            'rack'        => $rack,
            'status'      => $d['status']['value'] ?? $d['status'] ?? '',
            'primary_ip'  => $primaryIp ?: null,
            'tenant'      => $tenant ?: null,
            'category'    => $category,
            'description' => $d['description'] ?? null,
            'month'       => $currentMonth,
        ]);

        if ($category === 'switch') $switchCount++;
        elseif ($category === 'accesspoint') $apCount++;
        elseif ($category === 'router') $routerCount++;
    }

    AppLogger::info('netbox-sync', "Sync complete: {$switchCount} switches, {$apCount} APs, {$routerCount} routers, {$skipped} skipped ({$currentMonth})", [], $username);

    return [
        'switches' => $switchCount,
        'accesspoints' => $apCount,
        'routers' => $routerCount,
        'skipped' => $skipped,
        'month' => $currentMonth,
    ];
}
