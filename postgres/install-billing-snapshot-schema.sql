-- Billing snapshots: monthly frozen billing records for audit trail

CREATE TABLE IF NOT EXISTS billing_snapshot (
    id              SERIAL PRIMARY KEY,
    snapshot_month  VARCHAR(7) NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by      VARCHAR(100) DEFAULT 'system',
    status          VARCHAR(20) DEFAULT 'final',
    summary         JSONB NOT NULL,
    iaas_data       JSONB,
    license_data    JSONB,
    device_data     JSONB,
    UNIQUE (snapshot_month)
);

-- Billing configuration
CREATE TABLE IF NOT EXISTS billing_config (
    id              SERIAL PRIMARY KEY,
    config_key      VARCHAR(100) UNIQUE NOT NULL,
    config_value    VARCHAR(500) NOT NULL,
    description     VARCHAR(500),
    updated_at      TIMESTAMPTZ DEFAULT NOW()
);

-- Default: billing on the 1st of each month at 06:00
INSERT INTO billing_config (config_key, config_value, description) VALUES
    ('billing_day', '1', 'Tag des Monats an dem die Abrechnung erstellt wird (1-28)'),
    ('billing_hour', '6', 'Uhrzeit (Stunde, 0-23) zu der die Abrechnung erstellt wird'),
    ('billing_auto', 'true', 'Automatische Erstellung der Abrechnung (true/false)')
ON CONFLICT (config_key) DO NOTHING;
