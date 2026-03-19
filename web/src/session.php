<?php

require_once __DIR__ . '/db.php';

function createSession(int $userId, ?string $ip = null, ?string $ua = null): string
{
    $token = bin2hex(random_bytes(32));
    $db = getDb();

    // Clean expired sessions probabilistically (1 in 20 requests)
    if (random_int(1, 20) === 1) {
        $db->exec("DELETE FROM session_token WHERE expires_at < NOW()");
    }

    $stmt = $db->prepare("
        INSERT INTO session_token (user_id, token, expires_at, ip_address, user_agent)
        VALUES (:uid, :token, NOW() + INTERVAL '1 hour', :ip, :ua)
    ");
    $stmt->execute([
        'uid'   => $userId,
        'token' => $token,
        'ip'    => $ip,
        'ua'    => $ua ? substr($ua, 0, 512) : null,
    ]);

    return $token;
}

function validateSession(string $token): ?array
{
    $db = getDb();
    $stmt = $db->prepare("
        SELECT s.*, u.id AS user_id, u.username, u.email, u.display_name,
               u.role, u.theme, u.is_active, u.entra_oid
        FROM session_token s
        JOIN app_user u ON u.id = s.user_id
        WHERE s.token = :token AND s.expires_at > NOW() AND u.is_active = TRUE
    ");
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    // Sliding expiry: extend by 1 hour on each valid access
    $db->prepare("UPDATE session_token SET expires_at = NOW() + INTERVAL '1 hour' WHERE token = :token")
       ->execute(['token' => $token]);

    return [
        'id'           => (int) $row['user_id'],
        'username'     => $row['username'],
        'email'        => $row['email'],
        'display_name' => $row['display_name'],
        'role'         => $row['role'],
        'theme'        => $row['theme'],
        'entra_oid'    => $row['entra_oid'],
    ];
}

function destroySession(string $token): void
{
    $db = getDb();
    $db->prepare("DELETE FROM session_token WHERE token = :token")->execute(['token' => $token]);
}

function destroyAllSessionsForUser(int $userId, ?string $exceptToken = null): void
{
    $db = getDb();
    if ($exceptToken) {
        $stmt = $db->prepare("DELETE FROM session_token WHERE user_id = :uid AND token != :token");
        $stmt->execute(['uid' => $userId, 'token' => $exceptToken]);
    } else {
        $db->prepare("DELETE FROM session_token WHERE user_id = :uid")->execute(['uid' => $userId]);
    }
}

function setSessionCookie(string $token): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    setcookie('session_token', $token, [
        'expires'  => time() + 3600,
        'path'     => '/',
        'secure'   => $secure,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
}

function clearSessionCookie(): void
{
    setcookie('session_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
}
