CREATE TABLE IF NOT EXISTS app_log (
    id BIGSERIAL PRIMARY KEY,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    level VARCHAR(10) NOT NULL,       -- DEBUG, INFO, WARN, ERROR
    category VARCHAR(50) NOT NULL,    -- auth, vault, cmdb, sync, api, system, user
    message TEXT NOT NULL,
    context JSONB,                    -- optional structured data (user_id, ip, details)
    username VARCHAR(100)             -- who triggered it (null for system/cron)
);

CREATE INDEX IF NOT EXISTS idx_app_log_created ON app_log (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_app_log_level ON app_log (level);
CREATE INDEX IF NOT EXISTS idx_app_log_category ON app_log (category);

-- Auto-cleanup: automatically delete log entries older than 90 days.
-- This keeps the table from growing unboundedly and ensures compliance
-- with a 90-day retention policy. Run this as a scheduled job (e.g. pg_cron)
-- or invoke it periodically from the application.
DELETE FROM app_log WHERE created_at < NOW() - INTERVAL '90 days';
