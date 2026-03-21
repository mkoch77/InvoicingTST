-- Backup settings table (single-row configuration)
CREATE TABLE IF NOT EXISTS backup_settings (
    id INTEGER PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    schedule_enabled BOOLEAN NOT NULL DEFAULT false,
    schedule_time TIME NOT NULL DEFAULT '02:00',
    schedule_days JSONB NOT NULL DEFAULT '[1,2,3,4,5,6,0]',
    retention_count INTEGER NOT NULL DEFAULT 7,
    encryption_enabled BOOLEAN NOT NULL DEFAULT false,
    remote_enabled BOOLEAN NOT NULL DEFAULT false,
    remote_type VARCHAR(10) DEFAULT 'sftp',
    remote_host VARCHAR(255),
    remote_port INTEGER DEFAULT 22,
    remote_path VARCHAR(500) DEFAULT '/backups',
    remote_credential_key VARCHAR(100) DEFAULT 'backup_remote_credentials',
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Trigger to auto-update updated_at
CREATE OR REPLACE FUNCTION update_backup_settings_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_backup_settings_updated_at ON backup_settings;
CREATE TRIGGER trg_backup_settings_updated_at
    BEFORE UPDATE ON backup_settings
    FOR EACH ROW
    EXECUTE FUNCTION update_backup_settings_updated_at();

-- Insert default row
INSERT INTO backup_settings (id) VALUES (1) ON CONFLICT DO NOTHING;
