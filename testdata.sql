-- Testdaten fuer InvoicingAssets
-- Import: docker exec -i accounting-postgres psql -U accounting -d InvoicingAssets < testdata.sql

-- Betriebssysteme
INSERT INTO operating_system (name) VALUES
    ('Microsoft Windows Server 2022 Standard'),
    ('Microsoft Windows Server 2019 Standard'),
    ('Ubuntu 22.04.3 LTS'),
    ('Red Hat Enterprise Linux 9.2'),
    ('VMware Photon OS 4.0')
ON CONFLICT (name) DO NOTHING;

-- VMs fuer aktuellen Monat
INSERT INTO vm (hostname, dns_name, operating_system_id, vcpu, vram_mb, used_storage_gb, provisioned_storage_gb, power_state_id)
VALUES
    ('DC01',        'dc01.corp.local',        (SELECT id FROM operating_system WHERE name LIKE '%2022%'), 4,  8192,  45.30, 100.00, (SELECT id FROM power_state WHERE name='On')),
    ('DC02',        'dc02.corp.local',        (SELECT id FROM operating_system WHERE name LIKE '%2022%'), 4,  8192,  42.10, 100.00, (SELECT id FROM power_state WHERE name='On')),
    ('SQL01',       'sql01.corp.local',       (SELECT id FROM operating_system WHERE name LIKE '%2019%'), 8, 32768, 380.50, 500.00, (SELECT id FROM power_state WHERE name='On')),
    ('SQL02',       'sql02.corp.local',       (SELECT id FROM operating_system WHERE name LIKE '%2019%'), 8, 32768, 290.20, 500.00, (SELECT id FROM power_state WHERE name='Off')),
    ('WEB01',       'web01.corp.local',       (SELECT id FROM operating_system WHERE name LIKE '%Ubuntu%'), 2,  4096,  12.80,  50.00, (SELECT id FROM power_state WHERE name='On')),
    ('WEB02',       'web02.corp.local',       (SELECT id FROM operating_system WHERE name LIKE '%Ubuntu%'), 2,  4096,  11.50,  50.00, (SELECT id FROM power_state WHERE name='On')),
    ('APP01',       'app01.corp.local',       (SELECT id FROM operating_system WHERE name LIKE '%2022%'), 4, 16384,  85.60, 200.00, (SELECT id FROM power_state WHERE name='On')),
    ('APP02',       'app02.corp.local',       (SELECT id FROM operating_system WHERE name LIKE '%2022%'), 4, 16384,  78.30, 200.00, (SELECT id FROM power_state WHERE name='On')),
    ('MONITOR01',   'monitor01.corp.local',   (SELECT id FROM operating_system WHERE name LIKE '%Red Hat%'), 4,  8192,  65.40, 150.00, (SELECT id FROM power_state WHERE name='On')),
    ('BACKUP01',    'backup01.corp.local',    (SELECT id FROM operating_system WHERE name LIKE '%2022%'), 4, 16384, 420.00, 1000.00, (SELECT id FROM power_state WHERE name='On')),
    ('DEV01',       'dev01.corp.local',       (SELECT id FROM operating_system WHERE name LIKE '%Ubuntu%'), 8, 16384, 120.50, 300.00, (SELECT id FROM power_state WHERE name='On')),
    ('TEST01',      'test01.corp.local',      (SELECT id FROM operating_system WHERE name LIKE '%2019%'), 4,  8192,  55.00, 150.00, (SELECT id FROM power_state WHERE name='Suspended')),
    ('PROXY01',     'proxy01.corp.local',     (SELECT id FROM operating_system WHERE name LIKE '%Photon%'), 2,  2048,   5.20,  20.00, (SELECT id FROM power_state WHERE name='On')),
    ('MAIL01',      'mail01.corp.local',      (SELECT id FROM operating_system WHERE name LIKE '%2022%'), 4, 16384, 210.80, 400.00, (SELECT id FROM power_state WHERE name='On')),
    ('FILE01',      'file01.corp.local',      (SELECT id FROM operating_system WHERE name LIKE '%2019%'), 4,  8192, 890.30, 2000.00, (SELECT id FROM power_state WHERE name='On')),
    ('DC01-OLD',    'dc01-old.corp.local',    (SELECT id FROM operating_system WHERE name LIKE '%2019%'), 2,  4096,  38.00,  80.00, (SELECT id FROM power_state WHERE name='Off')),
    ('PRINT01',     'print01.corp.local',     (SELECT id FROM operating_system WHERE name LIKE '%2019%'), 2,  4096,  15.60,  60.00, (SELECT id FROM power_state WHERE name='Off')),
    ('JENKINS01',   'jenkins01.corp.local',   (SELECT id FROM operating_system WHERE name LIKE '%Ubuntu%'), 4,  8192,  95.00, 200.00, (SELECT id FROM power_state WHERE name='On')),
    ('K8S-NODE01',  'k8s-node01.corp.local',  (SELECT id FROM operating_system WHERE name LIKE '%Ubuntu%'), 8, 32768, 110.40, 300.00, (SELECT id FROM power_state WHERE name='On')),
    ('K8S-NODE02',  'k8s-node02.corp.local',  (SELECT id FROM operating_system WHERE name LIKE '%Ubuntu%'), 8, 32768, 105.80, 300.00, (SELECT id FROM power_state WHERE name='On'));

-- IP-Adressen
INSERT INTO vm_ip_address (vm_id, ip_address) VALUES
    ((SELECT id FROM vm WHERE hostname='DC01' ORDER BY id LIMIT 1),       '10.0.1.10'),
    ((SELECT id FROM vm WHERE hostname='DC02' ORDER BY id LIMIT 1),       '10.0.1.11'),
    ((SELECT id FROM vm WHERE hostname='SQL01' LIMIT 1),                  '10.0.2.20'),
    ((SELECT id FROM vm WHERE hostname='SQL01' LIMIT 1),                  '10.0.3.20'),
    ((SELECT id FROM vm WHERE hostname='SQL02' LIMIT 1),                  '10.0.2.21'),
    ((SELECT id FROM vm WHERE hostname='WEB01' LIMIT 1),                  '10.0.4.30'),
    ((SELECT id FROM vm WHERE hostname='WEB02' LIMIT 1),                  '10.0.4.31'),
    ((SELECT id FROM vm WHERE hostname='APP01' LIMIT 1),                  '10.0.5.40'),
    ((SELECT id FROM vm WHERE hostname='APP02' LIMIT 1),                  '10.0.5.41'),
    ((SELECT id FROM vm WHERE hostname='MONITOR01' LIMIT 1),             '10.0.6.50'),
    ((SELECT id FROM vm WHERE hostname='BACKUP01' LIMIT 1),              '10.0.7.60'),
    ((SELECT id FROM vm WHERE hostname='DEV01' LIMIT 1),                  '10.0.8.70'),
    ((SELECT id FROM vm WHERE hostname='DEV01' LIMIT 1),                  '10.0.9.70'),
    ((SELECT id FROM vm WHERE hostname='TEST01' LIMIT 1),                '10.0.8.71'),
    ((SELECT id FROM vm WHERE hostname='PROXY01' LIMIT 1),               '10.0.10.80'),
    ((SELECT id FROM vm WHERE hostname='PROXY01' LIMIT 1),               '192.168.1.80'),
    ((SELECT id FROM vm WHERE hostname='MAIL01' LIMIT 1),                '10.0.11.90'),
    ((SELECT id FROM vm WHERE hostname='FILE01' LIMIT 1),                '10.0.12.100'),
    ((SELECT id FROM vm WHERE hostname='PRINT01' LIMIT 1),               '10.0.12.101'),
    ((SELECT id FROM vm WHERE hostname='JENKINS01' LIMIT 1),             '10.0.13.110'),
    ((SELECT id FROM vm WHERE hostname='K8S-NODE01' LIMIT 1),            '10.0.14.120'),
    ((SELECT id FROM vm WHERE hostname='K8S-NODE02' LIMIT 1),            '10.0.14.121');

-- VMs fuer Februar 2026 (leicht andere Werte, TEST01 war noch On, PRINT01 noch On)
INSERT INTO vm (hostname, dns_name, operating_system_id, vcpu, vram_mb, used_storage_gb, provisioned_storage_gb, power_state_id, exported_at, export_month)
VALUES
    ('DC01',        'dc01.corp.local',        (SELECT id FROM operating_system WHERE name LIKE '%2022%'), 4,  8192,  43.10, 100.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('DC02',        'dc02.corp.local',        (SELECT id FROM operating_system WHERE name LIKE '%2022%'), 4,  8192,  40.80, 100.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('SQL01',       'sql01.corp.local',       (SELECT id FROM operating_system WHERE name LIKE '%2019%'), 8, 32768, 365.20, 500.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('SQL02',       'sql02.corp.local',       (SELECT id FROM operating_system WHERE name LIKE '%2019%'), 8, 32768, 280.50, 500.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('WEB01',       'web01.corp.local',       (SELECT id FROM operating_system WHERE name LIKE '%Ubuntu%'), 2,  4096,  11.20,  50.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('WEB02',       'web02.corp.local',       (SELECT id FROM operating_system WHERE name LIKE '%Ubuntu%'), 2,  4096,  10.80,  50.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('APP01',       'app01.corp.local',       (SELECT id FROM operating_system WHERE name LIKE '%2022%'), 4, 16384,  80.20, 200.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('APP02',       'app02.corp.local',       (SELECT id FROM operating_system WHERE name LIKE '%2022%'), 4, 16384,  72.10, 200.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('MONITOR01',   'monitor01.corp.local',   (SELECT id FROM operating_system WHERE name LIKE '%Red Hat%'), 4,  8192,  60.10, 150.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('BACKUP01',    'backup01.corp.local',    (SELECT id FROM operating_system WHERE name LIKE '%2022%'), 4, 16384, 395.00, 1000.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('DEV01',       'dev01.corp.local',       (SELECT id FROM operating_system WHERE name LIKE '%Ubuntu%'), 8, 16384, 112.30, 300.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('TEST01',      'test01.corp.local',      (SELECT id FROM operating_system WHERE name LIKE '%2019%'), 4,  8192,  50.40, 150.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('PROXY01',     'proxy01.corp.local',     (SELECT id FROM operating_system WHERE name LIKE '%Photon%'), 2,  2048,   4.90,  20.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('MAIL01',      'mail01.corp.local',      (SELECT id FROM operating_system WHERE name LIKE '%2022%'), 4, 16384, 195.60, 400.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('FILE01',      'file01.corp.local',      (SELECT id FROM operating_system WHERE name LIKE '%2019%'), 4,  8192, 850.10, 2000.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('DC01-OLD',    'dc01-old.corp.local',    (SELECT id FROM operating_system WHERE name LIKE '%2019%'), 2,  4096,  38.00,  80.00, (SELECT id FROM power_state WHERE name='Off'),       '2026-02-15', '2026-02'),
    ('PRINT01',     'print01.corp.local',     (SELECT id FROM operating_system WHERE name LIKE '%2019%'), 2,  4096,  15.60,  60.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('JENKINS01',   'jenkins01.corp.local',   (SELECT id FROM operating_system WHERE name LIKE '%Ubuntu%'), 4,  8192,  88.50, 200.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('K8S-NODE01',  'k8s-node01.corp.local',  (SELECT id FROM operating_system WHERE name LIKE '%Ubuntu%'), 8, 32768, 102.10, 300.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02'),
    ('K8S-NODE02',  'k8s-node02.corp.local',  (SELECT id FROM operating_system WHERE name LIKE '%Ubuntu%'), 8, 32768,  98.40, 300.00, (SELECT id FROM power_state WHERE name='On'),        '2026-02-15', '2026-02');

-- IP-Adressen fuer Februar
INSERT INTO vm_ip_address (vm_id, ip_address)
SELECT v.id, a.ip FROM vm v, (VALUES
    ('DC01',       inet '10.0.1.10'),
    ('DC02',       inet '10.0.1.11'),
    ('SQL01',      inet '10.0.2.20'),
    ('SQL01',      inet '10.0.3.20'),
    ('SQL02',      inet '10.0.2.21'),
    ('WEB01',      inet '10.0.4.30'),
    ('WEB02',      inet '10.0.4.31'),
    ('APP01',      inet '10.0.5.40'),
    ('APP02',      inet '10.0.5.41'),
    ('MONITOR01',  inet '10.0.6.50'),
    ('BACKUP01',   inet '10.0.7.60'),
    ('DEV01',      inet '10.0.8.70'),
    ('DEV01',      inet '10.0.9.70'),
    ('TEST01',     inet '10.0.8.71'),
    ('PROXY01',    inet '10.0.10.80'),
    ('PROXY01',    inet '192.168.1.80'),
    ('MAIL01',     inet '10.0.11.90'),
    ('FILE01',     inet '10.0.12.100'),
    ('PRINT01',    inet '10.0.12.101'),
    ('JENKINS01',  inet '10.0.13.110'),
    ('K8S-NODE01', inet '10.0.14.120'),
    ('K8S-NODE02', inet '10.0.14.121')
) AS a(hostname, ip)
WHERE v.hostname = a.hostname AND v.export_month = '2026-02';
