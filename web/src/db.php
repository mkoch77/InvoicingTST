<?php

function getDb(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host = getenv('PGHOST') ?: 'localhost';
    $port = getenv('PGPORT') ?: '5432';
    $db   = getenv('PGDATABASE') ?: 'InvoicingAssets';
    $user = getenv('PGUSER') ?: 'accounting';

    // Read password: copied secret (www-data readable) > Docker secret > env var
    $pass = null;
    $secretPaths = [
        '/var/www/secrets/pg_password',    // Copied by start.sh for www-data
        '/run/secrets/pg_password',         // Docker secret (root only, works for CLI)
    ];
    foreach ($secretPaths as $path) {
        if (is_readable($path)) {
            $pass = trim(file_get_contents($path));
            break;
        }
    }
    $passFile = getenv('PGPASSWORD_FILE');
    if (!$pass && $passFile && is_readable($passFile)) {
        $pass = trim(file_get_contents($passFile));
    }
    if (!$pass) {
        $pass = getenv('PGPASSWORD') ?: 'changeme';
    }

    $dsn = "pgsql:host={$host};port={$port};dbname={$db}";

    // Retry loop: wait for database to become available
    for ($i = 0; $i < 15; $i++) {
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return $pdo;
        } catch (PDOException $e) {
            if ($i < 14) {
                sleep(2);
            } else {
                throw $e;
            }
        }
    }

    throw new \RuntimeException('Database not reachable');
}
