<?php

require_once __DIR__ . '/../../src/middleware.php';
require_once __DIR__ . '/../../src/backup.php';

header('Content-Type: application/json');

$user = requireRole('admin');
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';

        if ($action === 'download') {
            $file = $_GET['file'] ?? '';
            if (!$file) {
                http_response_code(400);
                echo json_encode(['error' => 'Dateiname fehlt']);
                exit;
            }
            // downloadBackup handles headers and streaming
            downloadBackup($file);
            exit;
        }

        // Default: list backups and settings
        echo json_encode([
            'backups'  => listBackups(),
            'settings' => getBackupSettings(),
        ]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $input['action'] ?? '';

        if ($action === 'create') {
            $result = createBackup(true);
            echo json_encode(['ok' => true, 'backup' => $result]);
            exit;
        }

        if ($action === 'save_settings') {
            $settings = $input['settings'] ?? [];
            if (empty($settings)) {
                http_response_code(400);
                echo json_encode(['error' => 'Keine Einstellungen übergeben']);
                exit;
            }
            saveBackupSettings($settings);
            AppLogger::info('backup', 'Backup-Einstellungen gespeichert', $settings, $user['username'] ?? null);
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($action === 'test_remote') {
            $result = testRemoteConnection();
            echo json_encode($result);
            exit;
        }

        http_response_code(400);
        echo json_encode(['error' => 'Unbekannte Aktion']);
        exit;
    }

    if ($method === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $filename = $input['filename'] ?? '';

        if (!$filename) {
            http_response_code(400);
            echo json_encode(['error' => 'Dateiname fehlt']);
            exit;
        }

        if (deleteBackup($filename)) {
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Backup nicht gefunden']);
        }
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    AppLogger::error('backup', "API-Fehler: {$e->getMessage()}", [
        'trace' => $e->getTraceAsString(),
    ], $user['username'] ?? null);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
