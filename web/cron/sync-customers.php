#!/usr/bin/env php
<?php
/**
 * Cron job: Sync customers from Jira Assets CMDB.
 * Runs hourly via cron inside the web container.
 */

require_once __DIR__ . '/../src/customers.php';
require_once __DIR__ . '/../src/logger.php';

try {
    $result = syncCustomersFromCmdb();
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] CMDB Sync: {$result['created']} neu, {$result['updated']} aktualisiert, {$result['deactivated']} deaktiviert\n";
} catch (Exception $e) {
    AppLogger::error('sync', "CMDB Cron-Sync fehlgeschlagen: {$e->getMessage()}", [
        'exception' => get_class($e),
    ]);
    $ts = date('Y-m-d H:i:s');
    fwrite(STDERR, "[{$ts}] CMDB Sync Fehler: {$e->getMessage()}\n");
    exit(1);
}
