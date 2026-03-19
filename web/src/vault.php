<?php

require_once __DIR__ . '/db.php';

function getVaultKey(): string
{
    $key = getenv('VAULT_KEY');
    if ($key === false || $key === '') {
        throw new RuntimeException('VAULT_KEY environment variable is not set');
    }
    return $key;
}

function vaultEncrypt(string $plaintext): string
{
    $key = hash('sha256', getVaultKey(), true); // 32 bytes
    $iv = random_bytes(12);
    $tag = '';

    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if ($ciphertext === false) {
        throw new RuntimeException('Encryption failed');
    }

    return base64_encode($iv . $tag . $ciphertext);
}

function vaultDecrypt(string $encrypted): string
{
    $key = hash('sha256', getVaultKey(), true);
    $raw = base64_decode($encrypted, true);

    if ($raw === false || strlen($raw) < 28) {
        throw new RuntimeException('Invalid encrypted data');
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plaintext === false) {
        throw new RuntimeException('Decryption failed');
    }

    return $plaintext;
}

function listVaultSecrets(): array
{
    $db = getDb();
    $stmt = $db->query('SELECT id, key_name, description, created_at, updated_at FROM vault ORDER BY key_name');
    return $stmt->fetchAll();
}

function getVaultSecret(string $keyName): ?string
{
    $db = getDb();
    $stmt = $db->prepare('SELECT encrypted_value FROM vault WHERE key_name = :key_name');
    $stmt->execute(['key_name' => $keyName]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return vaultDecrypt($row['encrypted_value']);
}

function setVaultSecret(string $keyName, string $plaintext, string $description = ''): void
{
    $db = getDb();
    $encrypted = vaultEncrypt($plaintext);

    $stmt = $db->prepare(
        'INSERT INTO vault (key_name, encrypted_value, description, created_at, updated_at)
         VALUES (:key_name, :encrypted_value, :description, NOW(), NOW())
         ON CONFLICT (key_name) DO UPDATE
         SET encrypted_value = :encrypted_value2, description = :description2, updated_at = NOW()'
    );

    $stmt->execute([
        'key_name'          => $keyName,
        'encrypted_value'   => $encrypted,
        'description'       => $description,
        'encrypted_value2'  => $encrypted,
        'description2'      => $description,
    ]);
}

function deleteVaultSecret(string $keyName): bool
{
    $db = getDb();
    $stmt = $db->prepare('DELETE FROM vault WHERE key_name = :key_name');
    $stmt->execute(['key_name' => $keyName]);
    return $stmt->rowCount() > 0;
}
