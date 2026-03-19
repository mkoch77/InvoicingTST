<?php

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/vault.php';
require_once __DIR__ . '/../../src/logger.php';

header('Content-Type: application/json');

$user = requireRole('admin');
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    echo json_encode(listVaultSecrets());
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    if (empty($input['key_name']) || !isset($input['value'])) {
        http_response_code(400);
        echo json_encode(['error' => 'key_name and value are required']);
        exit;
    }

    setVaultSecret($input['key_name'], $input['value'], $input['description'] ?? '');
    AppLogger::info('vault', "Secret gespeichert: {$input['key_name']}", null, $user['username'] ?? null);
    echo json_encode(['ok' => true]);
    exit;
}

if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    if (empty($input['key_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'key_name is required']);
        exit;
    }

    if (deleteVaultSecret($input['key_name'])) {
        AppLogger::warn('vault', "Secret gelöscht: {$input['key_name']}", null, $user['username'] ?? null);
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Secret not found']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
