<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function listUsers(): array
{
    $db = getDb();
    $stmt = $db->query("
        SELECT id, username, email, display_name, role, theme,
               entra_oid, is_active, created_at, updated_at
        FROM app_user
        ORDER BY username
    ");
    return $stmt->fetchAll();
}

function createUser(array $data): array
{
    $db = getDb();

    $stmt = $db->prepare("
        INSERT INTO app_user (username, email, password_hash, display_name, role, entra_oid)
        VALUES (:username, :email, :password_hash, :display_name, :role, :entra_oid)
        RETURNING id
    ");
    $stmt->execute([
        'username'      => $data['username'],
        'email'         => $data['email'] ?: null,
        'password_hash' => !empty($data['password']) ? hashPassword($data['password']) : null,
        'display_name'  => $data['display_name'] ?: $data['username'],
        'role'          => $data['role'] ?? 'readonly',
        'entra_oid'     => $data['entra_oid'] ?: null,
    ]);

    $id = (int) $stmt->fetchColumn();
    return getUserById($id);
}

function updateUser(int $id, array $data): ?array
{
    $db = getDb();

    $fields = [];
    $params = ['id' => $id];

    foreach (['email', 'display_name', 'role', 'entra_oid'] as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "$f = :$f";
            $params[$f] = $data[$f] ?: null;
        }
    }

    if (array_key_exists('is_active', $data)) {
        $fields[] = "is_active = :is_active";
        $params['is_active'] = $data['is_active'] ? 'true' : 'false';
    }

    if (!empty($data['password'])) {
        $fields[] = "password_hash = :password_hash";
        $params['password_hash'] = hashPassword($data['password']);
    }

    if (empty($fields)) {
        return getUserById($id);
    }

    $fields[] = "updated_at = NOW()";
    $sql = "UPDATE app_user SET " . implode(', ', $fields) . " WHERE id = :id";
    $db->prepare($sql)->execute($params);

    return getUserById($id);
}
