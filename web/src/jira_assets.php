<?php
/**
 * Jira Assets (CMDB) API client — Service Account Scoped API-Token.
 *
 * Required vault secrets:
 *   jira_api_token      – Service Account Scoped API-Token (Bearer)
 *   jira_workspace_id   – Assets Workspace-ID
 *
 * API-Pfad: https://api.atlassian.com/jsm/assets/workspace/{workspaceId}/v1/...
 */

require_once __DIR__ . '/vault.php';
require_once __DIR__ . '/logger.php';

class JiraAssetsClient
{
    private string $workspaceId;
    private string $apiToken;

    public function __construct()
    {
        $this->workspaceId = getVaultSecret('jira_workspace_id') ?? '';
        $this->apiToken    = getVaultSecret('jira_api_token') ?? '';

        if (!$this->workspaceId) {
            throw new \RuntimeException('Vault-Secret "jira_workspace_id" fehlt');
        }
        if (!$this->apiToken) {
            throw new \RuntimeException('Vault-Secret "jira_api_token" fehlt. Bitte setup-jira.sh ausfuehren.');
        }
    }

    /**
     * Build the Assets REST API URL.
     */
    private function assetsUrl(string $path): string
    {
        return "https://api.atlassian.com/jsm/assets/workspace/{$this->workspaceId}/v1{$path}";
    }

    /**
     * Execute an API request.
     */
    private function request(string $method, string $url, ?array $body = null): array
    {
        $headers = [
            "Authorization: Bearer {$this->apiToken}",
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
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

        $data = json_decode($response, true) ?? [];

        if ($status === 401) {
            throw new \RuntimeException('Jira Assets API: Zugriff verweigert (401). API-Token oder Scopes pruefen.');
        }

        if ($status >= 400) {
            $msg = $data['errorMessages'][0] ?? $data['message'] ?? "HTTP {$status}";
            throw new \RuntimeException("Jira Assets API error: {$msg}");
        }

        return $data;
    }

    // ── Public API methods ──

    public function getSchemas(): array
    {
        return $this->request('GET', $this->assetsUrl('/objectschema/list'));
    }

    public function getObjectTypes(int $schemaId): array
    {
        $result = $this->request('GET', $this->assetsUrl("/objectschema/{$schemaId}/objecttypes"));
        if (!empty($result)) {
            return $result;
        }
        return $this->request('GET', $this->assetsUrl("/objectschema/{$schemaId}/objecttypes/flat"));
    }

    public function getObjectTypeAttributes(int $objectTypeId): array
    {
        return $this->request('GET', $this->assetsUrl("/objecttype/{$objectTypeId}/attributes"));
    }

    public function searchObjects(string $aql, int $startAt = 0, int $maxResults = 50, bool $includeAttributes = true): array
    {
        $params = http_build_query(['startAt' => $startAt, 'maxResults' => $maxResults]);
        return $this->request('POST', $this->assetsUrl('/object/aql') . '?' . $params, [
            'qlQuery'                => $aql,
            'includeAttributes'      => $includeAttributes,
            'includeAttributesDeep'  => 1,
        ]);
    }

    public function searchAllObjects(string $aql, bool $includeAttributes = true): array
    {
        $all     = [];
        $startAt = 0;
        $perPage = 100;
        do {
            $result  = $this->searchObjects($aql, $startAt, $perPage, $includeAttributes);
            $entries = $result['values'] ?? $result['objectEntries'] ?? [];
            $all     = array_merge($all, $entries);
            $startAt += count($entries);
            $isLast  = ($result['isLast'] ?? $result['last'] ?? true) || empty($entries);
        } while (!$isLast);
        return $all;
    }

    public function getObject(int $id): array
    {
        return $this->request('GET', $this->assetsUrl("/object/{$id}"));
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
}
