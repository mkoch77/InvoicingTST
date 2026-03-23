-- Company structure tables (TST companies + CostCenters)

CREATE TABLE IF NOT EXISTS company (
    id          SERIAL PRIMARY KEY,
    cmdb_key    VARCHAR(50) UNIQUE NOT NULL,
    name        VARCHAR(255) NOT NULL,
    location    VARCHAR(500) DEFAULT '',
    status      VARCHAR(50) DEFAULT 'Active',
    created_at  TIMESTAMPTZ DEFAULT NOW(),
    updated_at  TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS cost_center (
    id          SERIAL PRIMARY KEY,
    cmdb_key    VARCHAR(50) UNIQUE NOT NULL,
    name        VARCHAR(100) NOT NULL,
    cost_bearer VARCHAR(255) DEFAULT '',
    address     VARCHAR(500) DEFAULT '',
    customer    VARCHAR(255),
    status      VARCHAR(50) DEFAULT 'Active',
    company_id  INTEGER REFERENCES company(id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ DEFAULT NOW(),
    updated_at  TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_cost_center_company ON cost_center(company_id);

-- Updated_at triggers
CREATE OR REPLACE FUNCTION update_company_updated_at() RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_company_updated_at ON company;
CREATE TRIGGER trg_company_updated_at BEFORE UPDATE ON company
FOR EACH ROW EXECUTE FUNCTION update_company_updated_at();

DROP TRIGGER IF EXISTS trg_cost_center_updated_at ON cost_center;
CREATE TRIGGER trg_cost_center_updated_at BEFORE UPDATE ON cost_center
FOR EACH ROW EXECUTE FUNCTION update_company_updated_at();
