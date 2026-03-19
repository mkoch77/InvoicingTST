<?php

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/auth.php';
require_once __DIR__ . '/../../../src/session.php';
require_once __DIR__ . '/../../../src/middleware.php';

$tenantId     = getenv('ENTRA_TENANT_ID');
$clientId     = getenv('ENTRA_CLIENT_ID');
$clientSecret = getenv('ENTRA_CLIENT_SECRET');
$redirectUri  = getenv('ENTRA_REDIRECT_URI');

if (!$tenantId || !$clientId || !$clientSecret) {
    http_response_code(501);
    echo 'Entra ID is not configured';
    exit;
}

session_start();

// Validate state
if (empty($_GET['state']) || empty($_SESSION['oauth2state']) || $_GET['state'] !== $_SESSION['oauth2state']) {
    unset($_SESSION['oauth2state']);
    session_write_close();
    http_response_code(400);
    echo 'Invalid OAuth state';
    exit;
}

unset($_SESSION['oauth2state']);
session_write_close();

if (!empty($_GET['error'])) {
    header('Location: /login.html?error=' . urlencode($_GET['error_description'] ?? $_GET['error']));
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

try {
    $token = $provider->getAccessToken('authorization_code', ['code' => $_GET['code']]);
    $claims = $provider->getResourceOwner($token)->toArray();

    $oid   = $claims['oid'] ?? null;
    $email = $claims['email'] ?? $claims['upn'] ?? null;
    $name  = $claims['name'] ?? $email ?? 'Unknown';

    if (!$oid) {
        header('Location: /login.html?error=' . urlencode('No OID in token'));
        exit;
    }

    // Look up user by Entra OID
    $user = getUserByEntraOid($oid);

    // If not found, try linking by email
    if (!$user && $email) {
        $user = getUserByEmail($email);
        if ($user) {
            $db = getDb();
            $db->prepare("UPDATE app_user SET entra_oid = :oid, updated_at = NOW() WHERE id = :id")
               ->execute(['oid' => $oid, 'id' => $user['id']]);
        }
    }

    if (!$user) {
        header('Location: /login.html?error=' . urlencode('No account found. Contact an administrator.'));
        exit;
    }

    $ip = getClientIp();
    $sessionToken = createSession((int) $user['id'], $ip, $_SERVER['HTTP_USER_AGENT'] ?? null);
    setSessionCookie($sessionToken);

    header('Location: /');
    exit;

} catch (Exception $e) {
    header('Location: /login.html?error=' . urlencode('Authentication failed: ' . $e->getMessage()));
    exit;
}
