<?php
/**
 * Cron: Sync Netbox devices → CMDB2 (hourly, all devices).
 */
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/netbox_cmdb_sync.php';

try {
    $result = syncNetboxToCmdb(0, 'cron'); // 0 = no limit, sync all
    echo date('Y-m-d H:i:s') . " Netbox→CMDB sync: {$result['created']} created, {$result['updated']} updated, {$result['errors']} errors\n";
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " ERROR: " . $e->getMessage() . "\n";
}
