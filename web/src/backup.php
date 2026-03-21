<?php
/**
 * Backup system for PostgreSQL database.
 * Backups are stored in /var/www/backups/ as .sql.gz files (or .sql.gz.enc if encrypted).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vault.php';
require_once __DIR__ . '/logger.php';

function getBackupDir(): string
{
    $dir = '/var/www/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

function getBackupSettings(): array
{
    $db = getDb();
    $stmt = $db->query('SELECT * FROM backup_settings WHERE id = 1');
    $row = $stmt->fetch();

    if (!$row) {
        return [
            'schedule_enabled'     => false,
            'schedule_time'        => '02:00',
            'schedule_days'        => [1, 2, 3, 4, 5, 6, 0],
            'retention_count'      => 7,
            'encryption_enabled'   => false,
            'remote_enabled'       => false,
            'remote_type'          => 'sftp',
            'remote_host'          => '',
            'remote_port'          => 22,
            'remote_path'          => '/backups',
            'remote_credential_key' => 'backup_remote_credentials',
        ];
    }

    // Decode JSON fields
    $row['schedule_days'] = json_decode($row['schedule_days'], true) ?? [1, 2, 3, 4, 5, 6, 0];
    $row['schedule_enabled'] = (bool) $row['schedule_enabled'];
    $row['encryption_enabled'] = (bool) $row['encryption_enabled'];
    $row['remote_enabled'] = (bool) $row['remote_enabled'];
    $row['retention_count'] = (int) $row['retention_count'];
    $row['remote_port'] = (int) $row['remote_port'];

    return $row;
}

function saveBackupSettings(array $settings): void
{
    $db = getDb();

    $stmt = $db->prepare("
        INSERT INTO backup_settings (id, schedule_enabled, schedule_time, schedule_days,
            retention_count, encryption_enabled, remote_enabled, remote_type,
            remote_host, remote_port, remote_path, remote_credential_key, updated_at)
        VALUES (1, :schedule_enabled, :schedule_time, :schedule_days,
            :retention_count, :encryption_enabled, :remote_enabled, :remote_type,
            :remote_host, :remote_port, :remote_path, :remote_credential_key, NOW())
        ON CONFLICT (id) DO UPDATE SET
            schedule_enabled = EXCLUDED.schedule_enabled,
            schedule_time = EXCLUDED.schedule_time,
            schedule_days = EXCLUDED.schedule_days,
            retention_count = EXCLUDED.retention_count,
            encryption_enabled = EXCLUDED.encryption_enabled,
            remote_enabled = EXCLUDED.remote_enabled,
            remote_type = EXCLUDED.remote_type,
            remote_host = EXCLUDED.remote_host,
            remote_port = EXCLUDED.remote_port,
            remote_path = EXCLUDED.remote_path,
            remote_credential_key = EXCLUDED.remote_credential_key,
            updated_at = NOW()
    ");

    $stmt->execute([
        'schedule_enabled'      => $settings['schedule_enabled'] ? 't' : 'f',
        'schedule_time'         => $settings['schedule_time'] ?? '02:00',
        'schedule_days'         => json_encode($settings['schedule_days'] ?? [1, 2, 3, 4, 5, 6, 0]),
        'retention_count'       => (int) ($settings['retention_count'] ?? 7),
        'encryption_enabled'    => ($settings['encryption_enabled'] ?? false) ? 't' : 'f',
        'remote_enabled'        => ($settings['remote_enabled'] ?? false) ? 't' : 'f',
        'remote_type'           => $settings['remote_type'] ?? 'sftp',
        'remote_host'           => $settings['remote_host'] ?? '',
        'remote_port'           => (int) ($settings['remote_port'] ?? 22),
        'remote_path'           => $settings['remote_path'] ?? '/backups',
        'remote_credential_key' => $settings['remote_credential_key'] ?? 'backup_remote_credentials',
    ]);
}

function validateBackupFilename(string $filename): bool
{
    // Only allow alphanumeric, dots, dashes, underscores — no path separators
    return (bool) preg_match('/^[a-zA-Z0-9._-]+$/', $filename);
}

function createBackup(bool $manual = true): array
{
    $settings = getBackupSettings();
    $backupDir = getBackupDir();
    $username = $manual ? AppLogger::currentUser() : null;

    $host = getenv('PGHOST') ?: 'localhost';
    $port = getenv('PGPORT') ?: '5432';
    $dbname = getenv('PGDATABASE') ?: 'InvoicingAssets';
    $user = getenv('PGUSER') ?: 'accounting';

    // Read password
    $passFile = getenv('PGPASSWORD_FILE');
    if ($passFile && is_readable($passFile)) {
        $pass = trim(file_get_contents($passFile));
    } else {
        $pass = getenv('PGPASSWORD') ?: 'changeme';
    }

    $timestamp = date('Y-m-d_His');
    $baseFilename = "backup_{$timestamp}.sql.gz";
    $filepath = "{$backupDir}/{$baseFilename}";

    // 1. Run pg_dump and gzip
    $cmd = sprintf(
        'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -d %s | gzip > %s 2>&1',
        escapeshellarg($pass),
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg($user),
        escapeshellarg($dbname),
        escapeshellarg($filepath)
    );

    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0 || !file_exists($filepath) || filesize($filepath) === 0) {
        $error = implode("\n", $output);
        AppLogger::error('backup', "Backup fehlgeschlagen: {$error}", ['exit_code' => $exitCode], $username);
        throw new RuntimeException("pg_dump failed (exit code {$exitCode}): {$error}");
    }

    $encrypted = false;
    $finalFilename = $baseFilename;

    // 2. Encrypt if enabled
    if ($settings['encryption_enabled']) {
        $encryptedPath = $filepath . '.enc';

        $encKey = getVaultSecret('backup_encryption_key');
        if (!$encKey) {
            AppLogger::error('backup', 'Verschlüsselungskey backup_encryption_key nicht im Vault gefunden', null, $username);
            throw new RuntimeException('Encryption key backup_encryption_key not found in vault');
        }

        $plainData = file_get_contents($filepath);
        $key = hash('sha256', $encKey, true);
        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plainData,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            unlink($filepath);
            AppLogger::error('backup', 'Backup-Verschlüsselung fehlgeschlagen', null, $username);
            throw new RuntimeException('Backup encryption failed');
        }

        // Write: IV (12) + Tag (16) + Ciphertext
        file_put_contents($encryptedPath, $iv . $tag . $ciphertext);

        // Remove unencrypted file
        unlink($filepath);
        $filepath = $encryptedPath;
        $finalFilename = $baseFilename . '.enc';
        $encrypted = true;
    }

    $filesize = filesize($filepath);

    // 3. Apply retention
    $deleted = applyRetention();

    // 4. Upload to remote if enabled
    $remoteCopied = false;
    if ($settings['remote_enabled']) {
        try {
            $remoteCopied = uploadToRemote($filepath);
        } catch (Throwable $e) {
            AppLogger::warn('backup', "Remote-Upload fehlgeschlagen: {$e->getMessage()}", null, $username);
        }
    }

    // 5. Log
    $source = $manual ? 'manuell' : 'geplant';
    AppLogger::info('backup', "Backup erstellt ({$source}): {$finalFilename}", [
        'filename'      => $finalFilename,
        'size'          => $filesize,
        'encrypted'     => $encrypted,
        'remote_copied' => $remoteCopied,
        'retention_deleted' => $deleted,
    ], $username);

    return [
        'filename'      => $finalFilename,
        'size'          => $filesize,
        'encrypted'     => $encrypted,
        'remote_copied' => $remoteCopied,
    ];
}

function listBackups(): array
{
    $dir = getBackupDir();
    $files = glob("{$dir}/backup_*.sql.gz*");
    $backups = [];

    foreach ($files as $file) {
        $basename = basename($file);
        $backups[] = [
            'filename'   => $basename,
            'size'       => filesize($file),
            'created_at' => date('Y-m-d H:i:s', filemtime($file)),
            'encrypted'  => str_ends_with($basename, '.enc'),
        ];
    }

    // Sort by date descending
    usort($backups, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

    return $backups;
}

function deleteBackup(string $filename): bool
{
    if (!validateBackupFilename($filename)) {
        throw new InvalidArgumentException('Ungültiger Dateiname');
    }

    $filepath = getBackupDir() . '/' . $filename;

    if (!file_exists($filepath)) {
        return false;
    }

    $result = unlink($filepath);

    if ($result) {
        AppLogger::warn('backup', "Backup gelöscht: {$filename}", null, AppLogger::currentUser());
    }

    return $result;
}

function downloadBackup(string $filename): void
{
    if (!validateBackupFilename($filename)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiger Dateiname']);
        exit;
    }

    $filepath = getBackupDir() . '/' . $filename;

    if (!file_exists($filepath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Backup nicht gefunden']);
        exit;
    }

    $size = filesize($filepath);

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: no-cache, must-revalidate');

    readfile($filepath);
    exit;
}

function uploadToRemote(string $filepath): bool
{
    $settings = getBackupSettings();
    $username = AppLogger::currentUser();

    if (!$settings['remote_enabled']) {
        return false;
    }

    $credKey = $settings['remote_credential_key'] ?: 'backup_remote_credentials';
    $credJson = getVaultSecret($credKey);

    if (!$credJson) {
        AppLogger::error('backup', "Remote-Zugangsdaten nicht im Vault gefunden: {$credKey}", null, $username);
        throw new RuntimeException("Remote credentials not found in vault: {$credKey}");
    }

    $creds = json_decode($credJson, true);
    if (!$creds || empty($creds['user'])) {
        throw new RuntimeException('Invalid remote credentials format (expected JSON with user/password)');
    }

    $remoteUser = $creds['user'];
    $remotePass = $creds['password'] ?? '';
    $host = $settings['remote_host'];
    $port = (int) $settings['remote_port'];
    $remotePath = rtrim($settings['remote_path'], '/') . '/' . basename($filepath);

    if ($settings['remote_type'] === 'sftp') {
        return uploadViaSftp($filepath, $host, $port, $remoteUser, $remotePass, $remotePath);
    } elseif ($settings['remote_type'] === 'ftp') {
        return uploadViaFtp($filepath, $host, $port, $remoteUser, $remotePass, $remotePath);
    }

    throw new RuntimeException("Unbekannter Remote-Typ: {$settings['remote_type']}");
}

function uploadViaSftp(string $localPath, string $host, int $port, string $user, string $pass, string $remotePath): bool
{
    // Try ssh2 extension first
    if (function_exists('ssh2_connect')) {
        $conn = @ssh2_connect($host, $port);
        if (!$conn) {
            throw new RuntimeException("SFTP-Verbindung zu {$host}:{$port} fehlgeschlagen");
        }

        if (!@ssh2_auth_password($conn, $user, $pass)) {
            throw new RuntimeException("SFTP-Authentifizierung fehlgeschlagen für {$user}@{$host}");
        }

        $sftp = @ssh2_sftp($conn);
        if (!$sftp) {
            throw new RuntimeException('SFTP-Subsystem konnte nicht gestartet werden');
        }

        $remoteDir = dirname($remotePath);
        @ssh2_sftp_mkdir($sftp, $remoteDir, 0750, true);

        $stream = @fopen("ssh2.sftp://{$sftp}{$remotePath}", 'w');
        if (!$stream) {
            throw new RuntimeException("Konnte Remote-Datei nicht öffnen: {$remotePath}");
        }

        $localStream = fopen($localPath, 'r');
        $written = stream_copy_to_stream($localStream, $stream);
        fclose($localStream);
        fclose($stream);

        return $written !== false;
    }

    // Fallback to scp shell command
    $cmd = sprintf(
        'sshpass -p %s scp -P %d -o StrictHostKeyChecking=no %s %s@%s:%s 2>&1',
        escapeshellarg($pass),
        $port,
        escapeshellarg($localPath),
        escapeshellarg($user),
        escapeshellarg($host),
        escapeshellarg($remotePath)
    );

    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException('SCP-Upload fehlgeschlagen: ' . implode("\n", $output));
    }

    return true;
}

function uploadViaFtp(string $localPath, string $host, int $port, string $user, string $pass, string $remotePath): bool
{
    $conn = @ftp_connect($host, $port, 30);
    if (!$conn) {
        throw new RuntimeException("FTP-Verbindung zu {$host}:{$port} fehlgeschlagen");
    }

    if (!@ftp_login($conn, $user, $pass)) {
        ftp_close($conn);
        throw new RuntimeException("FTP-Authentifizierung fehlgeschlagen für {$user}@{$host}");
    }

    ftp_pasv($conn, true);

    // Ensure remote directory exists
    $remoteDir = dirname($remotePath);
    @ftp_mkdir($conn, $remoteDir);

    $result = @ftp_put($conn, $remotePath, $localPath, FTP_BINARY);
    ftp_close($conn);

    if (!$result) {
        throw new RuntimeException("FTP-Upload fehlgeschlagen: {$remotePath}");
    }

    return true;
}

function applyRetention(): int
{
    $settings = getBackupSettings();
    $retentionCount = $settings['retention_count'];

    if ($retentionCount <= 0) {
        return 0;
    }

    $backups = listBackups(); // Already sorted by date desc
    $deleted = 0;

    if (count($backups) > $retentionCount) {
        $toDelete = array_slice($backups, $retentionCount);
        foreach ($toDelete as $backup) {
            if (deleteBackup($backup['filename'])) {
                $deleted++;
            }
        }
    }

    return $deleted;
}

function testRemoteConnection(): array
{
    $settings = getBackupSettings();

    if (!$settings['remote_host']) {
        return ['success' => false, 'message' => 'Kein Remote-Host konfiguriert'];
    }

    $credKey = $settings['remote_credential_key'] ?: 'backup_remote_credentials';
    $credJson = getVaultSecret($credKey);

    if (!$credJson) {
        return ['success' => false, 'message' => "Zugangsdaten nicht im Vault gefunden: {$credKey}"];
    }

    $creds = json_decode($credJson, true);
    if (!$creds || empty($creds['user'])) {
        return ['success' => false, 'message' => 'Ungültiges Format der Zugangsdaten'];
    }

    $host = $settings['remote_host'];
    $port = (int) $settings['remote_port'];
    $user = $creds['user'];
    $pass = $creds['password'] ?? '';

    try {
        if ($settings['remote_type'] === 'sftp') {
            if (function_exists('ssh2_connect')) {
                $conn = @ssh2_connect($host, $port);
                if (!$conn) {
                    return ['success' => false, 'message' => "Verbindung zu {$host}:{$port} fehlgeschlagen"];
                }
                if (!@ssh2_auth_password($conn, $user, $pass)) {
                    return ['success' => false, 'message' => "Authentifizierung fehlgeschlagen für {$user}@{$host}"];
                }
                return ['success' => true, 'message' => "SFTP-Verbindung zu {$host}:{$port} erfolgreich"];
            }

            // Fallback: test with ssh
            $cmd = sprintf(
                'sshpass -p %s ssh -p %d -o StrictHostKeyChecking=no -o ConnectTimeout=10 %s@%s "echo ok" 2>&1',
                escapeshellarg($pass),
                $port,
                escapeshellarg($user),
                escapeshellarg($host)
            );
            exec($cmd, $output, $exitCode);
            if ($exitCode === 0) {
                return ['success' => true, 'message' => "SSH-Verbindung zu {$host}:{$port} erfolgreich"];
            }
            return ['success' => false, 'message' => 'SSH-Verbindung fehlgeschlagen: ' . implode(' ', $output)];

        } elseif ($settings['remote_type'] === 'ftp') {
            $conn = @ftp_connect($host, $port, 10);
            if (!$conn) {
                return ['success' => false, 'message' => "FTP-Verbindung zu {$host}:{$port} fehlgeschlagen"];
            }
            if (!@ftp_login($conn, $user, $pass)) {
                ftp_close($conn);
                return ['success' => false, 'message' => "FTP-Authentifizierung fehlgeschlagen für {$user}@{$host}"];
            }
            ftp_close($conn);
            return ['success' => true, 'message' => "FTP-Verbindung zu {$host}:{$port} erfolgreich"];
        }

        return ['success' => false, 'message' => "Unbekannter Remote-Typ: {$settings['remote_type']}"];

    } catch (Throwable $e) {
        return ['success' => false, 'message' => "Fehler: {$e->getMessage()}"];
    }
}
