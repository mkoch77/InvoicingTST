<?php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/auth.php';

function requireAuth(): array
{
    $token = $_COOKIE['session_token'] ?? null;

    if (!$token) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }

    $user = validateSession($token);
    if (!$user) {
        clearSessionCookie();
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Session expired']);
        exit;
    }

    return $user;
}

function requireRole(string ...$roles): array
{
    $user = requireAuth();

    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Insufficient permissions']);
        exit;
    }

    return $user;
}

function getClientIp(): string
{
    return $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
}
