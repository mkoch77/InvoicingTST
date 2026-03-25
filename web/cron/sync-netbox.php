<?php
/**
 * Cron job: Sync network devices from Netbox.
 * Runs hourly at :50.
 */

require_once __DIR__ . '/../src/netbox.php';

try {
    $result = syncNetboxDevices('cron');
    echo date('Y-m-d H:i:s') . " Netbox sync: {$result['switches']} switches, {$result['accesspoints']} APs ({$result['month']})\n";
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " Netbox sync FAILED: {$e->getMessage()}\n";
}
