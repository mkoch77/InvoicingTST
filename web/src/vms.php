<?php

require_once __DIR__ . '/db.php';

function fetchMonths(): array
{
    $db = getDb();
    $stmt = $db->query("
        SELECT DISTINCT TO_CHAR(exported_at, 'YYYY-MM') AS month
        FROM vm
        ORDER BY month DESC
    ");
    return array_column($stmt->fetchAll(), 'month');
}

function fetchVMs(?string $month = null): array
{
    $db = getDb();

    $query = "
        SELECT
            v.id,
            v.hostname,
            v.dns_name,
            os.name AS operating_system,
            v.vcpu,
            v.vram_mb,
            v.used_storage_gb,
            v.provisioned_storage_gb,
            ps.name AS power_state,
            v.exported_at,
            v.customer_id,
            c.code AS customer_code,
            c.name AS customer_name,
            (SELECT ssm.cmdb_customer FROM server_service_mapping ssm
             WHERE UPPER(ssm.hostname) = UPPER(v.hostname) LIMIT 1) AS cmdb_customer,
            ARRAY_AGG(ip.ip_address::TEXT ORDER BY ip.ip_address)
                FILTER (WHERE ip.ip_address IS NOT NULL) AS ip_addresses
        FROM vm v
        LEFT JOIN operating_system os ON os.id = v.operating_system_id
        LEFT JOIN power_state ps ON ps.id = v.power_state_id
        LEFT JOIN vm_ip_address ip ON ip.vm_id = v.id
        LEFT JOIN customer c ON c.id = v.customer_id
    ";
    $params = [];

    if ($month !== null && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $query .= "
        WHERE v.exported_at >= :start::DATE
          AND v.exported_at < (:start::DATE + INTERVAL '1 month')
        ";
        $params['start'] = $month . '-01';
    }

    $query .= "
        GROUP BY v.id, os.name, ps.name, c.code, c.name
        ORDER BY v.hostname, v.exported_at DESC
    ";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Parse PostgreSQL array for ip_addresses
    foreach ($rows as &$row) {
        $raw = $row['ip_addresses'];
        if ($raw !== null) {
            $row['ip_addresses'] = parsePgArray($raw);
        } else {
            $row['ip_addresses'] = [];
        }
    }

    return $rows;
}

function parsePgArray(?string $pgArray): array
{
    if ($pgArray === null || $pgArray === '{}') {
        return [];
    }
    // Remove outer braces and split
    $inner = trim($pgArray, '{}');
    if ($inner === '') {
        return [];
    }
    return array_map(function ($v) {
        return trim($v, '"');
    }, str_getcsv($inner));
}
