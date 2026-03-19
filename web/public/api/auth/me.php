<?php

require_once __DIR__ . '/../../../src/middleware.php';

header('Content-Type: application/json');

// Auto-create admin if no admins exist
ensureDefaultAdmin();

$user = requireAuth();
echo json_encode($user);
