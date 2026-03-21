<?php
/**
 * Backup Cron Job - runs every minute, checks if a backup is scheduled for now.
 * Only creates a backup if schedule_enabled is true and the current time/day matches.
 */

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/backup.php';
require_once __DIR__ . '/../src/logger.php';

try {
    $settings = getBackupSettings();

    if (!$settings['schedule_enabled']) {
        exit(0);
    }

    // Check if current day is in schedule_days
    $currentDay = (int) date('w'); // 0=Sunday, 6=Saturday
    $scheduleDays = $settings['schedule_days'];
    if (!in_array($currentDay, $scheduleDays)) {
        exit(0);
    }

    // Check if current time matches schedule_time (within the same minute)
    $scheduleTime = substr($settings['schedule_time'], 0, 5); // HH:MM
    $currentTime = date('H:i');
    if ($currentTime !== $scheduleTime) {
        exit(0);
    }

    // Prevent double-run: check if a backup was already created today
    $backups = listBackups();
    $today = date('Y-m-d');
    foreach ($backups as $b) {
        if (strpos($b['filename'], "backup_{$today}") === 0) {
            // Already have a backup for today
            exit(0);
        }
    }

    AppLogger::info('backup', 'Scheduled backup starting');
    $result = createBackup(false);
    AppLogger::info('backup', "Scheduled backup completed: {$result['filename']} ({$result['size']} bytes)", $result);

} catch (Exception $ex) {
    AppLogger::error('backup', 'Scheduled backup failed: ' . $ex->getMessage());
    exit(1);
}
