-- Schema for VeeamOne VM export data (normalized)
-- Run once against your PostgreSQL database:
--   psql -h <host> -U <user> -d InvoicingAssets -f install-postgres-schema.sql

-- Lookup table: operating systems
CREATE TABLE IF NOT EXISTS operating_system (
    id   SERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE
);

-- Lookup table: power states
CREATE TABLE IF NOT EXISTS power_state (
    id   SERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE
);

-- Pre-populate the three known power states
INSERT INTO power_state (name) VALUES ('On'), ('Off'), ('Suspended')
ON CONFLICT (name) DO NOTHING;

-- Main VM table with foreign keys to lookup tables
CREATE TABLE IF NOT EXISTS vm (
    id                      SERIAL PRIMARY KEY,
    hostname                TEXT NOT NULL,
    dns_name                TEXT,
    operating_system_id     INTEGER REFERENCES operating_system(id),
    vcpu                    INTEGER,
    vram_mb                 INTEGER,
    used_storage_gb         DOUBLE PRECISION,
    provisioned_storage_gb  DOUBLE PRECISION,
    power_state_id          INTEGER REFERENCES power_state(id),
    exported_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    export_month            TEXT NOT NULL DEFAULT to_char(NOW(), 'YYYY-MM')
);

-- IP addresses: one row per address (1NF, replaces comma-separated string)
CREATE TABLE IF NOT EXISTS vm_ip_address (
    id         SERIAL PRIMARY KEY,
    vm_id      INTEGER NOT NULL REFERENCES vm(id) ON DELETE CASCADE,
    ip_address INET NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_vm_hostname ON vm (hostname);
CREATE INDEX IF NOT EXISTS idx_vm_exported_at ON vm (exported_at);
CREATE INDEX IF NOT EXISTS idx_vm_ip_address_vm_id ON vm_ip_address (vm_id);

-- Pro VM darf jede IP nur einmal gespeichert werden
CREATE UNIQUE INDEX IF NOT EXISTS idx_vm_ip_address_unique
    ON vm_ip_address (vm_id, ip_address);

-- Pro Hostname darf nur ein Eintrag pro Monat existieren
CREATE UNIQUE INDEX IF NOT EXISTS idx_vm_hostname_month
    ON vm (hostname, export_month);
