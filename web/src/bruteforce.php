<?php

require_once __DIR__ . '/db.php';

const MAX_USER_ATTEMPTS = 5;
const MAX_IP_ATTEMPTS   = 20;
const LOCKOUT_WINDOW    = 15; // minutes

function recordAttempt(string $username, string $ip, bool $success): void
{
    $db = getDb();
    $stmt = $db->prepare("
        INSERT INTO login_attempt (username, ip_address, success)
        VALUES (:u, :ip, :s)
    ");
    $stmt->execute(['u' => $username, 'ip' => $ip, 's' => $success ? 'true' : 'false']);

    // Cleanup old attempts (> 24h) probabilistically
    if (random_int(1, 50) === 1) {
        $db->exec("DELETE FROM login_attempt WHERE attempted_at < NOW() - INTERVAL '24 hours'");
    }
}

function isLockedOut(string $username, string $ip): bool
{
    $db = getDb();

    // Check per-username lockout
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM login_attempt
        WHERE username = :u AND success = FALSE
          AND attempted_at > NOW() - INTERVAL '" . LOCKOUT_WINDOW . " minutes'
    ");
    $stmt->execute(['u' => $username]);
    if ((int) $stmt->fetchColumn() >= MAX_USER_ATTEMPTS) {
        return true;
    }

    // Check per-IP lockout
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM login_attempt
        WHERE ip_address = :ip AND success = FALSE
          AND attempted_at > NOW() - INTERVAL '" . LOCKOUT_WINDOW . " minutes'
    ");
    $stmt->execute(['ip' => $ip]);
    if ((int) $stmt->fetchColumn() >= MAX_IP_ATTEMPTS) {
        return true;
    }

    return false;
}

function getRemainingLockoutSeconds(string $username, string $ip): int
{
    $db = getDb();

    $stmt = $db->prepare("
        SELECT MAX(attempted_at) FROM login_attempt
        WHERE (username = :u OR ip_address = :ip) AND success = FALSE
          AND attempted_at > NOW() - INTERVAL '" . LOCKOUT_WINDOW . " minutes'
    ");
    $stmt->execute(['u' => $username, 'ip' => $ip]);
    $latest = $stmt->fetchColumn();

    if (!$latest) return 0;

    $unlockAt = strtotime($latest) + (LOCKOUT_WINDOW * 60);
    return max(0, $unlockAt - time());
}
