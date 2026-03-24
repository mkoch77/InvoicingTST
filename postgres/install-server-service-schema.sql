CREATE TABLE IF NOT EXISTS server_service_mapping (
    hostname VARCHAR(255) PRIMARY KEY,
    it_service VARCHAR(500) NOT NULL,
    cmdb_customer VARCHAR(255),
    cost_center_number VARCHAR(50),
    cmdb_key VARCHAR(50),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_ssm_service ON server_service_mapping(it_service);
CREATE INDEX IF NOT EXISTS idx_ssm_cost_center ON server_service_mapping(cost_center_number);
