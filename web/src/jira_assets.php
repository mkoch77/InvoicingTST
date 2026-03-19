<?php
/**
 * Jira Assets (CMDB) API client with OAuth 2.0 (3LO).
 *
 * Required vault secrets:
 *   jira_client_id      – OAuth App Client ID (developer.atlassian.com)
 *   jira_client_secret   – OAuth App Client Secret
 *   jira_workspace_id    – Assets workspace ID
 *   jira_cloud_id        – Atlassian Cloud ID (auto-detected after auth)
 *   jira_refresh_token   – OAuth refresh token (set after initial authorization)
 *   jira_access_token    – Cached access token (auto-managed)
 */

require_once __DIR__ . '/vault.php';

class JiraAssetsClient
{
    private string $workspaceId;
    private string $cloudId;
    private string $accessToken;

    public function __construct()
    {
        $this->workspaceId = getVaultSecret('jira_workspace_id') ?? '';
        $this->cloudId     = getVaultSecret('jira_cloud_id') ?? '';

        if (!$this->workspaceId) {
            throw new \RuntimeException('Vault-Secret "jira_workspace_id" fehlt');
        }

        $this->accessToken = $this->getValidAccessToken();

        // Auto-detect cloud ID if not set
        if (!$this->cloudId) {
            $this->cloudId = $this->detectCloudId();
        }
    }

    /**
     * Get a valid access token, refreshing if needed.
     */
    private function getValidAccessToken(): string
    {
        // Try cached access token first
        $token = getVaultSecret('jira_access_token');
        if ($token) {
            return $token;
        }

        // Refresh
        return $this->refreshAccessToken();
    }

    /**
     * Use refresh token to get a new access token.
     */
    private function refreshAccessToken(): string
    {
        $clientId     = getVaultSecret('jira_client_id') ?? '';
        $clientSecret = getVaultSecret('jira_client_secret') ?? '';
        $refreshToken = getVaultSecret('jira_refresh_token') ?? '';

        if (!$clientId || !$clientSecret || !$refreshToken) {
            throw new \RuntimeException(
                'Jira OAuth nicht konfiguriert. Bitte zuerst über Einstellungen > Vault autorisieren.'
            );
        }

        $response = $this->httpRequest('POST', 'https://auth.atlassian.com/oauth/token', [
            'grant_type'    => 'refresh_token',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ], false);

        if (empty($response['access_token'])) {
            // Refresh token may be expired — user needs to re-authorize
            throw new \RuntimeException(
                'Jira OAuth Token abgelaufen. Bitte erneut autorisieren über CMDB > Verbinden.'
            );
        }

        // Save new tokens in vault
        setVaultSecret('jira_access_token', $response['access_token'], 'OAuth Access Token (auto)');

        if (!empty($response['refresh_token'])) {
            setVaultSecret('jira_refresh_token', $response['refresh_token'], 'OAuth Refresh Token');
        }

        return $response['access_token'];
    }

    /**
     * Auto-detect the Atlassian Cloud ID from accessible resources.
     */
    private function detectCloudId(): string
    {
        $opts = [
            'http' => [
                'method'        => 'GET',
                'header'        => "Authorization: Bearer {$this->accessToken}\r\nAccept: application/json",
                'ignore_errors' => true,
                'timeout'       => 10,
            ],
        ];
        $ctx  = stream_context_create($opts);
        $resp = file_get_contents('https://api.atlassian.com/oauth/token/accessible-resources', false, $ctx);
        $data = json_decode($resp ?: '[]', true);

        if (!empty($data[0]['id'])) {
            $cloudId = $data[0]['id'];
            setVaultSecret('jira_cloud_id', $cloudId, 'Atlassian Cloud ID (auto-detected)');
            return $cloudId;
        }

        throw new \RuntimeException('Cloud ID konnte nicht ermittelt werden. Bitte erneut autorisieren.');
    }

    /**
     * Build the Assets REST API URL.
     */
    private function assetsUrl(string $path): string
    {
        return "https://api.atlassian.com/ex/jira/{$this->cloudId}/jsm/assets/workspace/{$this->workspaceId}/v1{$path}";
    }

    /**
     * Execute an API request. Retries once on 401 (token refresh).
     */
    private function request(string $method, string $url, ?array $body = null): array
    {
        $result = $this->doRequest($method, $url, $body);

        // If 401, try refreshing token once
        if ($result === null) {
            // Clear cached token and refresh
            setVaultSecret('jira_access_token', '', 'OAuth Access Token (auto)');
            $this->accessToken = $this->refreshAccessToken();
            $result = $this->doRequest($method, $url, $body);
        }

        if ($result === null) {
            throw new \RuntimeException('Jira Assets API: Zugriff verweigert nach Token-Refresh');
        }

        return $result;
    }

    /**
     * Single HTTP request to Assets API. Returns null on 401.
     */
    private function doRequest(string $method, string $url, ?array $body = null): ?array
    {
        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", [
                    "Authorization: Bearer {$this->accessToken}",
                    'Accept: application/json',
                    'Content-Type: application/json',
                ]),
                'ignore_errors' => true,
                'timeout'       => 30,
            ],
        ];

        if ($body !== null) {
            $opts['http']['content'] = json_encode($body);
        }

        $ctx      = stream_context_create($opts);
        $response = file_get_contents($url, false, $ctx);

        if ($response === false) {
            throw new \RuntimeException("Jira Assets API request failed: {$url}");
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $m)) {
            $status = (int) $m[0];
        }

        if ($status === 401) {
            return null; // Signal to refresh and retry
        }

        $data = json_decode($response, true) ?? [];

        if ($status >= 400) {
            $msg = $data['errorMessages'][0] ?? $data['message'] ?? "HTTP {$status}";
            throw new \RuntimeException("Jira Assets API error: {$msg}");
        }

        return $data;
    }

    /**
     * Low-level HTTP request (for OAuth token exchange).
     */
    private function httpRequest(string $method, string $url, array $body, bool $useBearerAuth = true): array
    {
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($useBearerAuth) {
            $headers[] = "Authorization: Bearer {$this->accessToken}";
        }

        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
                'content'       => json_encode($body),
                'ignore_errors' => true,
                'timeout'       => 15,
            ],
        ];

        $ctx  = stream_context_create($opts);
        $resp = file_get_contents($url, false, $ctx);

        return json_decode($resp ?: '{}', true) ?? [];
    }

    // ── Public API methods ──

    public function searchObjects(string $aql, int $page = 1, int $perPage = 50, bool $includeAttributes = true): array
    {
        return $this->request('POST', $this->assetsUrl('/object/aql'), [
            'qlQuery'                => $aql,
            'page'                   => $page,
            'resultPerPage'          => $perPage,
            'includeAttributes'      => $includeAttributes,
            'includeAttributesDeep'  => 1,
        ]);
    }

    public function searchAllObjects(string $aql, bool $includeAttributes = true): array
    {
        $all  = [];
        $page = 1;
        do {
            $result  = $this->searchObjects($aql, $page, 100, $includeAttributes);
            $entries = $result['values'] ?? $result['objectEntries'] ?? [];
            $all     = array_merge($all, $entries);
            $total   = $result['total'] ?? $result['totalFilterCount'] ?? count($entries);
            $page++;
        } while (count($all) < $total);
        return $all;
    }

    public function getObject(int $id): array
    {
        return $this->request('GET', $this->assetsUrl("/object/{$id}"));
    }

    public function getObjectTypes(int $schemaId): array
    {
        return $this->request('GET', $this->assetsUrl("/objectschema/{$schemaId}/objecttypes"));
    }

    public function getSchemas(): array
    {
        return $this->request('GET', $this->assetsUrl('/objectschema/list'));
    }

    public function getObjectTypeAttributes(int $objectTypeId): array
    {
        return $this->request('GET', $this->assetsUrl("/objecttype/{$objectTypeId}/attributes"));
    }

    public static function getAttributeValue(array $object, string $attributeName): ?string
    {
        foreach ($object['attributes'] ?? [] as $attr) {
            $name = $attr['objectTypeAttribute']['name'] ?? '';
            if (strcasecmp($name, $attributeName) === 0) {
                $values = $attr['objectAttributeValues'] ?? [];
                if (!empty($values)) {
                    return $values[0]['displayValue'] ?? $values[0]['value'] ?? null;
                }
            }
        }
        return null;
    }

    // ── OAuth helpers (static, used by auth endpoints) ──

    /**
     * Build the authorization URL for the initial OAuth consent.
     */
    public static function getAuthorizationUrl(string $redirectUri): string
    {
        $clientId = getVaultSecret('jira_client_id') ?? '';
        $state    = bin2hex(random_bytes(16));
        $_SESSION['jira_oauth_state'] = $state;

        $params = http_build_query([
            'audience'      => 'api.atlassian.com',
            'client_id'     => $clientId,
            'scope'         => 'read:servicemanagement-insight-objects offline_access',
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'response_type' => 'code',
            'prompt'        => 'consent',
        ]);

        return "https://auth.atlassian.com/authorize?{$params}";
    }

    /**
     * Exchange authorization code for tokens.
     */
    public static function exchangeCodeForTokens(string $code, string $redirectUri): array
    {
        $clientId     = getVaultSecret('jira_client_id') ?? '';
        $clientSecret = getVaultSecret('jira_client_secret') ?? '';

        $opts = [
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAccept: application/json",
                'content'       => json_encode([
                    'grant_type'    => 'authorization_code',
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'code'          => $code,
                    'redirect_uri'  => $redirectUri,
                ]),
                'ignore_errors' => true,
                'timeout'       => 15,
            ],
        ];

        $ctx  = stream_context_create($opts);
        $resp = file_get_contents('https://auth.atlassian.com/oauth/token', false, $ctx);
        $data = json_decode($resp ?: '{}', true) ?? [];

        if (empty($data['access_token'])) {
            throw new \RuntimeException('OAuth Token-Austausch fehlgeschlagen: ' . ($data['error_description'] ?? $data['error'] ?? 'Unbekannter Fehler'));
        }

        // Save tokens
        setVaultSecret('jira_access_token', $data['access_token'], 'OAuth Access Token (auto)');
        if (!empty($data['refresh_token'])) {
            setVaultSecret('jira_refresh_token', $data['refresh_token'], 'OAuth Refresh Token');
        }

        return $data;
    }
}
