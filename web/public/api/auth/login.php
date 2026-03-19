<?php

require_once __DIR__ . '/../../../src/auth.php';
require_once __DIR__ . '/../../../src/session.php';
require_once __DIR__ . '/../../../src/bruteforce.php';
require_once __DIR__ . '/../../../src/middleware.php';

header('Content-Type: application/json');

// Auto-create admin if no admins exist
ensureDefaultAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';
$ip = getClientIp();

if (!$username || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Username and password required']);
    exit;
}

// Brute force check
if (isLockedOut($username, $ip)) {
    $remaining = getRemainingLockoutSeconds($username, $ip);
    http_response_code(429);
    echo json_encode([
        'error'   => 'Too many failed attempts. Try again later.',
        'retry_after' => $remaining,
    ]);
    exit;
}

$user = getUserByUsername($username);

if (!$user || empty($user['password_hash']) || !verifyPassword($password, $user['password_hash'])) {
    recordAttempt($username, $ip, false);
    http_response_code(401);
    echo json_encode(['error' => 'Invalid username or password']);
    exit;
}

recordAttempt($username, $ip, true);

$token = createSession((int) $user['id'], $ip, $_SERVER['HTTP_USER_AGENT'] ?? null);
setSessionCookie($token);

echo json_encode([
    'id'           => (int) $user['id'],
    'username'     => $user['username'],
    'display_name' => $user['display_name'],
    'role'         => $user['role'],
    'theme'        => $user['theme'],
]);
