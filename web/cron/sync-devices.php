<?php
/**
 * Cron job: Sync Intune managed devices.
 * Runs hourly at :45.
 */

require_once __DIR__ . '/../src/intune.php';

try {
    $result = syncIntuneDevices('cron');
    echo date('Y-m-d H:i:s') . " Device sync: {$result['devices']} devices, {$result['cmdb']} CMDB-enriched ({$result['month']})\n";
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " Device sync FAILED: {$e->getMessage()}\n";
}
