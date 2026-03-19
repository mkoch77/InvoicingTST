-- Authentication & User Management Schema
-- Run after install-postgres-schema.sql

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'user_role') THEN
        CREATE TYPE user_role AS ENUM ('admin', 'operator', 'readonly');
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'theme_preference') THEN
        CREATE TYPE theme_preference AS ENUM ('system', 'dark', 'light');
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS app_user (
    id              SERIAL PRIMARY KEY,
    username        TEXT NOT NULL UNIQUE,
    email           TEXT UNIQUE,
    password_hash   TEXT,
    display_name    TEXT,
    role            user_role NOT NULL DEFAULT 'readonly',
    theme           theme_preference NOT NULL DEFAULT 'system',
    entra_oid       TEXT UNIQUE,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS session_token (
    id              SERIAL PRIMARY KEY,
    user_id         INTEGER NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    token           TEXT NOT NULL UNIQUE,
    expires_at      TIMESTAMPTZ NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    ip_address      INET,
    user_agent      TEXT
);

CREATE INDEX IF NOT EXISTS idx_session_token ON session_token (token);
CREATE INDEX IF NOT EXISTS idx_session_expires ON session_token (expires_at);

CREATE TABLE IF NOT EXISTS login_attempt (
    id              SERIAL PRIMARY KEY,
    username        TEXT NOT NULL,
    ip_address      INET NOT NULL,
    attempted_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    success         BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE INDEX IF NOT EXISTS idx_login_attempt_user_time ON login_attempt (username, attempted_at);
CREATE INDEX IF NOT EXISTS idx_login_attempt_ip_time ON login_attempt (ip_address, attempted_at);
