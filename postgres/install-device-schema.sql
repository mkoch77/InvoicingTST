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
