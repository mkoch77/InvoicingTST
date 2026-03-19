<?php
/**
 * API endpoint for Jira Assets (CMDB) queries.
 *
 * GET /api/jira-assets.php?action=schemas          – List all schemas
 * GET /api/jira-assets.php?action=types&schema=ID   – List object types in schema
 * GET /api/jira-assets.php?action=search&aql=QUERY  – Search objects by AQL
 * GET /api/jira-assets.php?action=object&id=ID      – Get single object
 * GET /api/jira-assets.php?action=status            – Check if Jira connection is configured
 */

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/jira_assets.php';

header('Content-Type: application/json');

$user = requireRole('admin');
$action = $_GET['action'] ?? '';

// Status check does not need a live connection
if ($action === 'status') {
    require_once __DIR__ . '/../../src/vault.php';
    $hasOAuth = !empty(getVaultSecret('jira_client_id'))
             && !empty(getVaultSecret('jira_client_secret'))
             && !empty(getVaultSecret('jira_workspace_id'));
    $hasTokens = !empty(getVaultSecret('jira_refresh_token'));
    echo json_encode([
        'configured'  => $hasOAuth && $hasTokens,
        'oauth_ready' => $hasOAuth,
        'authorized'  => $hasTokens,
    ]);
    exit;
}

try {
    $client = new JiraAssetsClient();
} catch (\RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

try {
    switch ($action) {
        case 'schemas':
            echo json_encode($client->getSchemas());
            break;

        case 'types':
            $schemaId = (int) ($_GET['schema'] ?? 0);
            if (!$schemaId) {
                http_response_code(400);
                echo json_encode(['error' => 'Parameter schema erforderlich']);
                exit;
            }
            echo json_encode($client->getObjectTypes($schemaId));
            break;

        case 'search':
            $aql = $_GET['aql'] ?? '';
            if (!$aql) {
                http_response_code(400);
                echo json_encode(['error' => 'Parameter aql erforderlich']);
                exit;
            }
            $page      = max(1, (int) ($_GET['page'] ?? 1));
            $perPage   = min(200, max(1, (int) ($_GET['perPage'] ?? 50)));
            $startAt   = ($page - 1) * $perPage;
            echo json_encode($client->searchObjects($aql, $startAt, $perPage));
            break;

        case 'object':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Parameter id erforderlich']);
                exit;
            }
            echo json_encode($client->getObject($id));
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unbekannte action. Erlaubt: status, schemas, types, search, object']);
            break;
    }
} catch (\RuntimeException $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()]);
}
