-- Customer / Kundenkürzel Schema
-- Run after install-auth-schema.sql

CREATE TABLE IF NOT EXISTS customer (
    id          SERIAL PRIMARY KEY,
    code        TEXT NOT NULL UNIQUE,
    name        TEXT NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Manual VM-to-customer overrides (persist across months)
-- hostname is the key, so the same hostname always gets the same customer
CREATE TABLE IF NOT EXISTS vm_customer_override (
    id          SERIAL PRIMARY KEY,
    hostname    TEXT NOT NULL UNIQUE,
    customer_id INTEGER NOT NULL REFERENCES customer(id) ON DELETE CASCADE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Add customer_id to vm table
ALTER TABLE vm ADD COLUMN IF NOT EXISTS customer_id INTEGER REFERENCES customer(id);
CREATE INDEX IF NOT EXISTS idx_vm_customer_id ON vm (customer_id);

-- Seed customer data
INSERT INTO customer (code, name) VALUES
    ('AL1', 'Allison'),
    ('AS1', 'Aldi Süd'),
    ('B2B', 'B2B'),
    ('BA1', 'BASF Lager'),
    ('BA2', 'BASF BPCN'),
    ('BA3', 'BASF BTC'),
    ('BA4', 'BASF RODIM'),
    ('BC1', 'Barry Callebaut'),
    ('BIB', 'Logisticus Biblis'),
    ('BOE', 'Bönen Henkel'),
    ('BP1', 'Business Partner'),
    ('CME', 'CME'),
    ('DA1', 'Dalli'),
    ('DA2', 'Daimler'),
    ('DA3', 'Danone Waters'),
    ('DM1', 'Di Martino'),
    ('DM2', 'dm-drogerie markt'),
    ('DPN', 'Delta ProNatura'),
    ('DRM', 'Dr. Malek / M3'),
    ('ECC', 'Werner & Mertz'),
    ('EU1', 'Eures'),
    ('EW1', 'EWS Applications'),
    ('FI1', 'Finnern'),
    ('GR1', 'Grace'),
    ('HF1', 'Henkell Freixenet'),
    ('HK1', 'Henkel Klebstoffe'),
    ('HK2', 'Henkel'),
    ('IL1', 'ILS'),
    ('IS1', 'Intersnack'),
    ('IT1', 'TST-IT'),
    ('ITT', 'IT Testserver'),
    ('KRS', 'Konsul-Ritter-Strasse'),
    ('KT1', 'LIDL Crossdock KT1'),
    ('KT2', 'LIDL Crossdock KT2'),
    ('LA1', 'Lamotte'),
    ('LI1', 'Lidl TK'),
    ('LI2', 'LIDL Nonfood'),
    ('LI3', 'LIDL/Dalli'),
    ('LIR', 'LIDL Retouren'),
    ('LO1', 'Logisticus'),
    ('MO1', 'Mondi'),
    ('MU1', 'MultiCom'),
    ('NH1', 'Nestlé NHC'),
    ('NN1', 'Nanu Nana'),
    ('OG1', 'Oceangate'),
    ('PE1', 'Penny'),
    ('PM1', 'Paul Müller'),
    ('PU1', 'Nestlé Purina'),
    ('RER', 'REWE Retouren'),
    ('RO1', 'Roche Diagnostics'),
    ('SC1', 'SC Johnson'),
    ('SH1', 'Shell'),
    ('TC1', 'Tchibo'),
    ('TR1', 'Transimpex'),
    ('TS1', 'TST GmbH'),
    ('UN1', 'Unilever'),
    ('UN2', 'Unisped'),
    ('VP1', 'Verpoorten')
ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name;
