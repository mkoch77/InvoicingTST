<?php
/**
 * Cron job: Check if billing snapshot should be created.
 * Runs hourly. On the configured billing day/hour, creates a snapshot
 * for the previous month.
 */

require_once __DIR__ . '/../src/billing_snapshot.php';

try {
    if (shouldCreateSnapshot()) {
        $prevMonth = (new DateTime('first day of last month'))->format('Y-m');
        $result = createBillingSnapshot($prevMonth, 'cron');
        echo date('Y-m-d H:i:s') . " Billing snapshot created for {$prevMonth}: Total {$result['grand_total']} EUR\n";
    }
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " Billing snapshot check: {$e->getMessage()}\n";
}
