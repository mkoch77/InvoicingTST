-- Intune managed devices (Notebooks/Clients)

CREATE TABLE IF NOT EXISTS intune_device (
    id                  SERIAL PRIMARY KEY,
    device_id           VARCHAR(100) UNIQUE NOT NULL,
    azure_ad_device_id  VARCHAR(100),
    device_name         VARCHAR(255) NOT NULL,
    serial_number       VARCHAR(100),
    manufacturer        VARCHAR(255),
    model               VARCHAR(255),
    user_display_name   VARCHAR(255),
    user_principal_name VARCHAR(255),
    compliance_state    VARCHAR(50),
    last_sync           TIMESTAMPTZ,
    cost_center         VARCHAR(50),
    company_name        VARCHAR(255),
    export_month        VARCHAR(7) NOT NULL DEFAULT to_char(NOW(), 'YYYY-MM'),
    updated_at          TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_intune_device_upn ON intune_device(user_principal_name);
CREATE INDEX IF NOT EXISTS idx_intune_device_month ON intune_device(export_month);
CREATE INDEX IF NOT EXISTS idx_intune_device_cc ON intune_device(cost_center);

-- Device pricing categories
CREATE TABLE IF NOT EXISTS device_pricing (
    id              SERIAL PRIMARY KEY,
    category_name   VARCHAR(100) UNIQUE NOT NULL,
    price           NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    match_rules     TEXT,
    sort_order      INTEGER DEFAULT 0,
    is_active       BOOLEAN DEFAULT TRUE
);

INSERT INTO device_pricing (category_name, price, match_rules, sort_order) VALUES
    ('HP 14 Zoll',      68.96,  'manufacturer=HP,model_contains=14', 1),
    ('HP 16 Zoll',      71.05,  'manufacturer=HP,model_contains=16', 2),
    ('Surface',         91.12,  'manufacturer=Microsoft,model_starts=Surface Laptop', 3),
    ('Surface Pro',     95.12,  'manufacturer=Microsoft,model_starts=Surface Pro', 4),
    ('Surface Hub 85',  733.52, 'manufacturer=Microsoft,model_contains=Surface Hub,model_contains=85', 5),
    ('Surface Hub 50',  282.42, 'manufacturer=Microsoft,model_contains=Surface Hub,model_contains=50', 6),
    ('Altgeräte',       36.12,  'fallback_old=true', 7),
    ('HP Dock',          4.46,  'manufacturer=HP,model_contains=Dock', 8),
    ('Alt Dock',        11.62,  'model_contains=Dock,not_manufacturer=HP', 9),
    ('Desktop',         55.49,  'model_contains=Desktop,or_model_contains=OptiPlex,or_model_contains=Tower,or_model_contains=Mini PC', 10)
ON CONFLICT (category_name) DO NOTHING;

-- Add pricing columns to intune_device
ALTER TABLE intune_device ADD COLUMN IF NOT EXISTS device_category VARCHAR(100);
ALTER TABLE intune_device ADD COLUMN IF NOT EXISTS device_price NUMERIC(12,2) DEFAULT 0.00;
