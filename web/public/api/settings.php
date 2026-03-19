<?php

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/logger.php';

header('Content-Type: application/json');

$user = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode([
        'id'           => $user['id'],
        'username'     => $user['username'],
        'email'        => $user['email'],
        'display_name' => $user['display_name'],
        'role'         => $user['role'],
        'theme'        => $user['theme'],
        'entra_oid'    => $user['entra_oid'],
    ]);
    exit;
}

if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $db = getDb();

    // Theme update
    if (isset($input['theme']) && in_array($input['theme'], ['system', 'dark', 'light'])) {
        $db->prepare("UPDATE app_user SET theme = :theme, updated_at = NOW() WHERE id = :id")
           ->execute(['theme' => $input['theme'], 'id' => $user['id']]);
    }

    // Display name update
    if (isset($input['display_name']) && trim($input['display_name'])) {
        $db->prepare("UPDATE app_user SET display_name = :name, updated_at = NOW() WHERE id = :id")
           ->execute(['name' => trim($input['display_name']), 'id' => $user['id']]);
    }

    // Password change
    if (!empty($input['new_password'])) {
        if (strlen($input['new_password']) < 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Password must be at least 8 characters']);
            exit;
        }
        $fullUser = getUserById($user['id']);
        if (!empty($fullUser['password_hash'])) {
            if (empty($input['current_password']) || !verifyPassword($input['current_password'], $fullUser['password_hash'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Current password is incorrect']);
                exit;
            }
        }
        $db->prepare("UPDATE app_user SET password_hash = :hash, updated_at = NOW() WHERE id = :id")
           ->execute(['hash' => hashPassword($input['new_password']), 'id' => $user['id']]);
        AppLogger::info('auth', "Passwort geändert", null, $user['username'] ?? null);
    }

    // Return updated user
    $updated = getUserById($user['id']);
    echo json_encode([
        'id'           => (int) $updated['id'],
        'username'     => $updated['username'],
        'email'        => $updated['email'],
        'display_name' => $updated['display_name'],
        'role'         => $updated['role'],
        'theme'        => $updated['theme'],
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
