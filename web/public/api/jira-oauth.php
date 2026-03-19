<?php
/**
 * Jira OAuth 2.0 endpoints.
 *
 * GET  /api/jira-oauth.php?action=authorize  – Redirect to Atlassian consent screen
 * GET  /api/jira-oauth.php?action=callback   – Handle OAuth callback with code
 */

session_start();

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/jira_assets.php';

$user   = requireRole('admin');
$action = $_GET['action'] ?? '';

// Build the redirect URI for this endpoint
$scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
$redirectUri = "{$scheme}://{$host}/api/jira-oauth.php?action=callback";

switch ($action) {
    case 'authorize':
        $url = JiraAssetsClient::getAuthorizationUrl($redirectUri);
        header("Location: {$url}");
        exit;

    case 'callback':
        $code  = $_GET['code'] ?? '';
        $state = $_GET['state'] ?? '';
        $error = $_GET['error'] ?? '';

        if ($error) {
            header('Content-Type: text/html');
            echo "<h2>Fehler: " . htmlspecialchars($error) . "</h2>";
            echo "<p>" . htmlspecialchars($_GET['error_description'] ?? '') . "</p>";
            echo '<p><a href="/admin/vault.html">Zurück zum Vault</a></p>';
            exit;
        }

        if (!$code) {
            http_response_code(400);
            echo 'Kein Authorization Code erhalten.';
            exit;
        }

        try {
            JiraAssetsClient::exchangeCodeForTokens($code, $redirectUri);

            // Redirect to CMDB page with success message
            header('Location: /cmdb.html?oauth=success');
            exit;
        } catch (\Exception $e) {
            header('Content-Type: text/html');
            echo "<h2>OAuth fehlgeschlagen</h2>";
            echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
            echo '<p><a href="/admin/vault.html">Zurück zum Vault</a></p>';
            exit;
        }

    default:
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unbekannte action. Erlaubt: authorize, callback']);
        break;
}
