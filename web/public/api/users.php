<?php

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/users.php';

header('Content-Type: application/json');

$currentUser = requireRole('admin');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(listUsers());
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'POST') {
    $required = ['username'];
    foreach ($required as $f) {
        if (empty($input[$f])) {
            http_response_code(400);
            echo json_encode(['error' => "Field '$f' is required"]);
            exit;
        }
    }
    if (empty($input['password']) && empty($input['entra_oid'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Either password or Entra OID required']);
        exit;
    }
    if (!empty($input['password']) && strlen($input['password']) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 8 characters']);
        exit;
    }
    if (!empty($input['role']) && !in_array($input['role'], ['admin', 'operator', 'readonly'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid role']);
        exit;
    }
    try {
        $user = createUser($input);
        http_response_code(201);
        echo json_encode($user);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'app_user_username_key')) {
            http_response_code(409);
            echo json_encode(['error' => 'Username already exists']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    exit;
}

if ($method === 'PUT') {
    $id = (int) ($input['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID required']);
        exit;
    }
    if (!empty($input['password']) && strlen($input['password']) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 8 characters']);
        exit;
    }
    $user = updateUser($id, $input);
    echo json_encode($user);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
