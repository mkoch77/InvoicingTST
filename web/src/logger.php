<?php
/**
 * Central application logger.
 * Writes structured log entries to the app_log database table.
 *
 * Log levels: DEBUG, INFO, WARN, ERROR
 * Categories: auth, vault, cmdb, sync, api, system, user, export
 */

require_once __DIR__ . '/db.php';

class AppLogger
{
    const DEBUG = 'DEBUG';
    const INFO  = 'INFO';
    const WARN  = 'WARN';
    const ERROR = 'ERROR';

    /**
     * Write a log entry.
     *
     * @param string      $level    DEBUG|INFO|WARN|ERROR
     * @param string      $category auth|vault|cmdb|sync|api|system|user|export
     * @param string      $message  Human-readable message
     * @param array|null  $context  Optional structured data
     * @param string|null $username Who triggered it (null for system/cron)
     */
    public static function log(string $level, string $category, string $message, ?array $context = null, ?string $username = null): void
    {
        try {
            $db = getDb();
            $stmt = $db->prepare("
                INSERT INTO app_log (level, category, message, context, username)
                VALUES (:level, :category, :message, :context, :username)
            ");
            $stmt->execute([
                'level'    => $level,
                'category' => $category,
                'message'  => $message,
                'context'  => $context ? json_encode($context) : null,
                'username' => $username,
            ]);

            // Probabilistic cleanup: ~1% chance per log write, delete entries older than 90 days
            if (random_int(1, 100) === 1) {
                $db->exec("DELETE FROM app_log WHERE created_at < NOW() - INTERVAL '90 days'");
            }
        } catch (\Throwable $e) {
            // Fallback to stderr if DB logging fails
            error_log("[{$level}] [{$category}] {$message} — DB log failed: {$e->getMessage()}");
        }
    }

    public static function debug(string $category, string $message, ?array $context = null, ?string $username = null): void
    {
        self::log(self::DEBUG, $category, $message, $context, $username);
    }

    public static function info(string $category, string $message, ?array $context = null, ?string $username = null): void
    {
        self::log(self::INFO, $category, $message, $context, $username);
    }

    public static function warn(string $category, string $message, ?array $context = null, ?string $username = null): void
    {
        self::log(self::WARN, $category, $message, $context, $username);
    }

    public static function error(string $category, string $message, ?array $context = null, ?string $username = null): void
    {
        self::log(self::ERROR, $category, $message, $context, $username);
    }

    /**
     * Get the username from the current session (for API requests).
     */
    public static function currentUser(): ?string
    {
        $token = $_COOKIE['session_token'] ?? null;
        if (!$token) return null;

        static $cached = null;
        if ($cached !== null) return $cached ?: null;

        try {
            require_once __DIR__ . '/session.php';
            $user = validateSession($token);
            $cached = $user ? ($user['username'] ?? $user['display_name'] ?? 'unknown') : '';
            return $cached ?: null;
        } catch (\Throwable $e) {
            $cached = '';
            return null;
        }
    }

    /**
     * Query logs for the log viewer.
     */
    public static function query(
        ?string $level = null,
        ?string $category = null,
        ?string $search = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        $db = getDb();
        $where = [];
        $params = [];

        if ($level) {
            $where[] = "level = :level";
            $params['level'] = $level;
        }
        if ($category) {
            $where[] = "category = :category";
            $params['category'] = $category;
        }
        if ($search) {
            $where[] = "(message ILIKE :search OR username ILIKE :search2)";
            $params['search']  = "%{$search}%";
            $params['search2'] = "%{$search}%";
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count total
        $countStmt = $db->prepare("SELECT COUNT(*) FROM app_log {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch page
        $stmt = $db->prepare("
            SELECT id, created_at, level, category, message, context, username
            FROM app_log {$whereClause}
            ORDER BY created_at DESC
            LIMIT :lim OFFSET :off
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue('off', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'total' => $total,
            'logs'  => $stmt->fetchAll(),
        ];
    }

    /**
     * Get available categories.
     */
    public static function categories(): array
    {
        $db = getDb();
        $stmt = $db->query("SELECT DISTINCT category FROM app_log ORDER BY category");
        return array_column($stmt->fetchAll(), 'category');
    }
}
