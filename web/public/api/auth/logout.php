<?php

require_once __DIR__ . '/../../../src/session.php';

header('Content-Type: application/json');

$token = $_COOKIE['session_token'] ?? null;
if ($token) {
    destroySession($token);
    clearSessionCookie();
}

echo json_encode(['ok' => true]);
