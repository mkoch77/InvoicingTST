-- Vault table for storing AES256-encrypted secrets
CREATE TABLE IF NOT EXISTS vault (
    id              SERIAL PRIMARY KEY,
    key_name        VARCHAR(100) UNIQUE NOT NULL,
    encrypted_value TEXT NOT NULL,
    description     TEXT,
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    updated_at      TIMESTAMPTZ DEFAULT NOW()
);

-- Trigger function to auto-update updated_at on row changes
CREATE OR REPLACE FUNCTION update_vault_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Attach the trigger to the vault table
DROP TRIGGER IF EXISTS trg_vault_updated_at ON vault;
CREATE TRIGGER trg_vault_updated_at
    BEFORE UPDATE ON vault
    FOR EACH ROW
    EXECUTE FUNCTION update_vault_updated_at();
