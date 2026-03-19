<?php

require_once __DIR__ . '/db.php';

function hashPassword(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT);
}

function verifyPassword(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

function generateToken(): string
{
    return bin2hex(random_bytes(32));
}

function getUserByUsername(string $username): ?array
{
    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM app_user WHERE username = :u AND is_active = TRUE");
    $stmt->execute(['u' => $username]);
    return $stmt->fetch() ?: null;
}

function getUserByEmail(string $email): ?array
{
    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM app_user WHERE email = :e AND is_active = TRUE");
    $stmt->execute(['e' => $email]);
    return $stmt->fetch() ?: null;
}

function getUserByEntraOid(string $oid): ?array
{
    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM app_user WHERE entra_oid = :oid AND is_active = TRUE");
    $stmt->execute(['oid' => $oid]);
    return $stmt->fetch() ?: null;
}

function getUserById(int $id): ?array
{
    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM app_user WHERE id = :id");
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: null;
}

function ensureDefaultAdmin(): void
{
    $db = getDb();
    $stmt = $db->query("SELECT COUNT(*) FROM app_user WHERE role = 'admin'");
    if ((int) $stmt->fetchColumn() === 0) {
        $stmt = $db->prepare("
            INSERT INTO app_user (username, password_hash, display_name, role)
            VALUES ('admin', :hash, 'Administrator', 'admin')
            ON CONFLICT (username) DO NOTHING
        ");
        $stmt->execute(['hash' => hashPassword('admin')]);
    }
}
