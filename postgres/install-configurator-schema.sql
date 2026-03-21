-- Configurator pricing settings (single-row configuration)
CREATE TABLE IF NOT EXISTS configurator_settings (
    id INTEGER PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    margin_percent NUMERIC(5,2) NOT NULL DEFAULT 15.00,
    network_price_per_sqm NUMERIC(10,2) NOT NULL DEFAULT 10.00,
    ap_price NUMERIC(10,2) NOT NULL DEFAULT 600.00,
    switch_price_monthly NUMERIC(10,2) NOT NULL DEFAULT 150.00,
    wp_admin_price_monthly NUMERIC(10,2) NOT NULL DEFAULT 120.00,
    wp_operative_price_monthly NUMERIC(10,2) NOT NULL DEFAULT 80.00,
    wp_scanner_price_monthly NUMERIC(10,2) NOT NULL DEFAULT 45.00,
    lic_m365_price_monthly NUMERIC(10,2) NOT NULL DEFAULT 22.00,
    lic_erp_price_monthly NUMERIC(10,2) NOT NULL DEFAULT 85.00,
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE OR REPLACE FUNCTION update_configurator_settings_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_configurator_settings_updated_at ON configurator_settings;
CREATE TRIGGER trg_configurator_settings_updated_at
    BEFORE UPDATE ON configurator_settings
    FOR EACH ROW
    EXECUTE FUNCTION update_configurator_settings_updated_at();

INSERT INTO configurator_settings (id) VALUES (1) ON CONFLICT DO NOTHING;
