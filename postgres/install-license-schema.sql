-- Microsoft 365 License tracking

CREATE TABLE IF NOT EXISTS license_sku (
    id              SERIAL PRIMARY KEY,
    sku_id          VARCHAR(100) UNIQUE NOT NULL,
    sku_part_number VARCHAR(100) NOT NULL,
    display_name    VARCHAR(255) NOT NULL DEFAULT '',
    price           NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    is_active       BOOLEAN DEFAULT TRUE,
    created_at      TIMESTAMPTZ DEFAULT NOW(),
    updated_at      TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS entra_user (
    id                  SERIAL PRIMARY KEY,
    entra_id            VARCHAR(100) UNIQUE NOT NULL,
    display_name        VARCHAR(255) NOT NULL,
    user_principal_name VARCHAR(255) NOT NULL,
    department          VARCHAR(255),
    street_address      VARCHAR(500),
    city                VARCHAR(255),
    cost_center         VARCHAR(50),
    company_name        VARCHAR(255),
    is_active           BOOLEAN DEFAULT TRUE,
    updated_at          TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS entra_license_assignment (
    id              SERIAL PRIMARY KEY,
    entra_user_id   INTEGER NOT NULL REFERENCES entra_user(id) ON DELETE CASCADE,
    license_sku_id  INTEGER NOT NULL REFERENCES license_sku(id) ON DELETE CASCADE,
    export_month    VARCHAR(7) NOT NULL DEFAULT to_char(NOW(), 'YYYY-MM'),
    assigned_at     TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE (entra_user_id, license_sku_id, export_month)
);

CREATE INDEX IF NOT EXISTS idx_ela_month ON entra_license_assignment(export_month);
CREATE INDEX IF NOT EXISTS idx_ela_sku ON entra_license_assignment(license_sku_id);
CREATE INDEX IF NOT EXISTS idx_entra_user_upn ON entra_user(user_principal_name);

-- Seed target license SKUs (prices to be configured in Stammdaten)
INSERT INTO license_sku (sku_id, sku_part_number, display_name) VALUES
    ('placeholder_spe_e3',              'SPE_E3',                       'Microsoft 365 E3'),
    ('placeholder_spe_e5',              'SPE_E5',                       'Microsoft 365 E5'),
    ('placeholder_intune_a_d',          'INTUNE_A_D',                   'Intune Device'),
    ('placeholder_project_plan3',       'PROJECT_PLAN3_DEPT',           'Project Plan 3'),
    ('placeholder_project_premium',     'PROJECTPREMIUM',               'Project Plan 5'),
    ('placeholder_project_pro',         'PROJECTPROFESSIONAL',          'Project Professional'),
    ('placeholder_visio',               'VISIOCLIENT',                  'Visio Plan 2'),
    ('placeholder_pbi_premium',         'PBI_PREMIUM_PER_USER',         'Power BI Premium Per User'),
    ('placeholder_teams_rooms',         'Microsoft_Teams_Rooms_Pro',    'Teams Rooms Pro'),
    ('placeholder_o365_no_teams',       'O365_w_o_Teams_Bundle_E3',     'Office 365 E3 (ohne Teams)'),
    ('placeholder_m365_f1',             'M365_F1_COMM',                 'Microsoft 365 F1')
ON CONFLICT (sku_id) DO NOTHING;
