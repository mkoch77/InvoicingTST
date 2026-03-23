#!/usr/bin/env php
<?php
/**
 * Cron job: Sync company structure (TST + CostCenters) from Jira Assets CMDB.
 * Runs hourly via cron inside the web container.
 */

require_once __DIR__ . '/../src/company.php';

try {
    $result = syncCompanyStructure('cron');
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] Company Sync: {$result['companies']} Firmen, {$result['cost_centers']} Kostenstellen\n";
} catch (Exception $e) {
    AppLogger::error('company-sync', "Cron-Sync fehlgeschlagen: {$e->getMessage()}", [
        'exception' => get_class($e),
    ]);
    $ts = date('Y-m-d H:i:s');
    fwrite(STDERR, "[{$ts}] Company Sync Fehler: {$e->getMessage()}\n");
    exit(1);
}
