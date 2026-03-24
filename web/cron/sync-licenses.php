<?php
/**
 * Cron job: Sync Microsoft 365 licenses from Entra ID.
 * Runs hourly at :15.
 */

require_once __DIR__ . '/../src/msgraph.php';

try {
    $result = syncEntraLicenses('cron');
    echo date('Y-m-d H:i:s') . " License sync: {$result['users']} users, {$result['assignments']} assignments ({$result['month']})\n";
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " License sync FAILED: {$e->getMessage()}\n";
}
