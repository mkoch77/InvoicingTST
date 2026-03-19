<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

$tenantId     = getenv('ENTRA_TENANT_ID');
$clientId     = getenv('ENTRA_CLIENT_ID');
$clientSecret = getenv('ENTRA_CLIENT_SECRET');
$redirectUri  = getenv('ENTRA_REDIRECT_URI');

if (!$tenantId || !$clientId || !$clientSecret) {
    http_response_code(501);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Entra ID is not configured']);
    exit;
}

$provider = new TheNetworg\OAuth2\Client\Provider\Azure([
    'clientId'     => $clientId,
    'clientSecret' => $clientSecret,
    'redirectUri'  => $redirectUri,
    'tenant'       => $tenantId,
    'scopes'       => ['openid', 'profile', 'email'],
]);

$provider->defaultEndPointVersion = TheNetworg\OAuth2\Client\Provider\Azure::ENDPOINT_VERSION_2_0;

session_start();
$authUrl = $provider->getAuthorizationUrl();
$_SESSION['oauth2state'] = $provider->getState();
session_write_close();

header('Location: ' . $authUrl);
exit;
