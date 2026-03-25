-- Netbox network devices (Switches & Access Points)

CREATE TABLE IF NOT EXISTS netbox_device (
    id                  SERIAL PRIMARY KEY,
    netbox_id           INTEGER UNIQUE NOT NULL,
    name                VARCHAR(255) NOT NULL,
    device_type         VARCHAR(255),
    device_role         VARCHAR(255),
    manufacturer        VARCHAR(255),
    model               VARCHAR(255),
    serial_number       VARCHAR(255),
    asset_tag           VARCHAR(255),
    site                VARCHAR(255),
    location            VARCHAR(255),
    rack                VARCHAR(255),
    status              VARCHAR(50),
    primary_ip          VARCHAR(100),
    tenant              VARCHAR(255),
    category            VARCHAR(50) NOT NULL DEFAULT 'switch',
    description         TEXT,
    export_month        VARCHAR(7) NOT NULL DEFAULT to_char(NOW(), 'YYYY-MM'),
    updated_at          TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_netbox_device_category ON netbox_device(category);
CREATE INDEX IF NOT EXISTS idx_netbox_device_month ON netbox_device(export_month);
CREATE INDEX IF NOT EXISTS idx_netbox_device_role ON netbox_device(device_role);
