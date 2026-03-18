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
    $pass = getenv('PGPASSWORD') ?: 'changeme';

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
