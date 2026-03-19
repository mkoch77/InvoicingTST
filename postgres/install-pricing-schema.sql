-- VM Pricing / Abrechnungsklassen Schema

-- Point factors for VM resources
CREATE TABLE IF NOT EXISTS pricing_factor (
    id          SERIAL PRIMARY KEY,
    resource    TEXT NOT NULL UNIQUE,
    points_per_unit DOUBLE PRECISION NOT NULL,
    unit        TEXT NOT NULL,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO pricing_factor (resource, points_per_unit, unit) VALUES
    ('vcpu',       17.5185, 'vCPU'),
    ('vram_gb',     7.2807, 'GB'),
    ('storage_gb',  0.1668, 'GB')
ON CONFLICT (resource) DO UPDATE SET
    points_per_unit = EXCLUDED.points_per_unit,
    unit = EXCLUDED.unit,
    updated_at = NOW();

-- Pricing tiers
CREATE TABLE IF NOT EXISTS pricing_tier (
    id              SERIAL PRIMARY KEY,
    class_name      TEXT NOT NULL UNIQUE,
    max_points      DOUBLE PRECISION NOT NULL,
    price           DOUBLE PRECISION NOT NULL,
    sort_order      INTEGER NOT NULL DEFAULT 0,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO pricing_tier (class_name, max_points, price, sort_order) VALUES
    ('XS-1',    75, 86.25,   1),
    ('XS-2',   150, 172.50,  2),
    ('XS-3',   225, 258.75,  3),
    ('XS-4',   300, 345.00,  4),
    ('XS-5',   375, 431.25,  5),
    ('S-1',    450, 517.50,  6),
    ('S-2',    525, 603.75,  7),
    ('S-3',    600, 690.00,  8),
    ('S-4',    675, 776.25,  9),
    ('S-5',    750, 862.50, 10),
    ('M-1',    825, 948.75, 11),
    ('M-2',    900, 1035.00, 12),
    ('M-3',    975, 1121.25, 13),
    ('M-4',   1050, 1207.50, 14),
    ('M-5',   1125, 1293.75, 15),
    ('L-1',   1200, 1380.00, 16),
    ('L-2',   1275, 1466.25, 17),
    ('L-3',   1350, 1552.50, 18),
    ('L-4',   1425, 1638.75, 19),
    ('L-5',   1500, 1725.00, 20),
    ('XL-1',  1575, 1811.25, 21),
    ('XL-2',  1650, 1897.50, 22),
    ('XL-3',  1725, 1983.75, 23),
    ('XL-4',  1800, 2070.00, 24),
    ('XL-5',  1875, 2156.25, 25),
    ('XXL-1', 1950, 2242.50, 26),
    ('XXL-2', 2025, 2328.75, 27),
    ('XXL-3', 2100, 2415.00, 28),
    ('XXL-4', 2175, 2501.25, 29),
    ('XXL-5', 2250, 2587.50, 30),
    ('XXL-6', 2325, 2673.75, 31),
    ('XXL-7', 2400, 2760.00, 32),
    ('XXL-8', 2475, 2846.25, 33),
    ('XXL-9', 2550, 2932.50, 34)
ON CONFLICT (class_name) DO UPDATE SET
    max_points = EXCLUDED.max_points,
    price = EXCLUDED.price,
    sort_order = EXCLUDED.sort_order,
    updated_at = NOW();
