<?php

require_once __DIR__ . '/db.php';

function listCustomers(): array
{
    $db = getDb();
    $stmt = $db->query("SELECT * FROM customer ORDER BY code");
    return $stmt->fetchAll();
}

function getCustomerById(int $id): ?array
{
    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM customer WHERE id = :id");
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: null;
}

function createCustomer(string $code, string $name): array
{
    $db = getDb();
    $stmt = $db->prepare("
        INSERT INTO customer (code, name) VALUES (:code, :name) RETURNING id
    ");
    $stmt->execute(['code' => strtoupper(trim($code)), 'name' => trim($name)]);
    return getCustomerById((int) $stmt->fetchColumn());
}

function updateCustomer(int $id, array $data): ?array
{
    $db = getDb();
    $fields = [];
    $params = ['id' => $id];

    if (array_key_exists('code', $data)) {
        $fields[] = "code = :code";
        $params['code'] = strtoupper(trim($data['code']));
    }
    if (array_key_exists('name', $data)) {
        $fields[] = "name = :name";
        $params['name'] = trim($data['name']);
    }
    if (array_key_exists('is_active', $data)) {
        $fields[] = "is_active = :is_active";
        $params['is_active'] = $data['is_active'] ? 'true' : 'false';
    }

    if (empty($fields)) return getCustomerById($id);

    $fields[] = "updated_at = NOW()";
    $db->prepare("UPDATE customer SET " . implode(', ', $fields) . " WHERE id = :id")->execute($params);
    return getCustomerById($id);
}

function deleteCustomer(int $id): bool
{
    $db = getDb();
    $stmt = $db->prepare("DELETE FROM customer WHERE id = :id");
    $stmt->execute(['id' => $id]);
    return $stmt->rowCount() > 0;
}

/**
 * Extract customer code from hostname.
 * For hostnames starting with F0, F2, F3: characters at positions 3-5 (1-based) = substr(2, 3)
 */
function extractCustomerCode(string $hostname): ?string
{
    $h = strtoupper($hostname);
    if (preg_match('/^F[023]/', $h) && strlen($h) >= 5) {
        return substr($h, 2, 3);
    }
    return null;
}

/**
 * Build a lookup map: code => customer_id
 */
function getCustomerCodeMap(): array
{
    $db = getDb();
    $stmt = $db->query("SELECT id, code FROM customer WHERE is_active = TRUE");
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[strtoupper($row['code'])] = (int) $row['id'];
    }
    return $map;
}

/**
 * Get all manual overrides: hostname => customer_id
 */
function getOverrideMap(): array
{
    $db = getDb();
    $stmt = $db->query("SELECT hostname, customer_id FROM vm_customer_override");
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[strtoupper($row['hostname'])] = (int) $row['customer_id'];
    }
    return $map;
}

/**
 * Set manual override for a hostname
 */
function setCustomerOverride(string $hostname, int $customerId): void
{
    $db = getDb();
    $db->prepare("
        INSERT INTO vm_customer_override (hostname, customer_id)
        VALUES (:hostname, :cid)
        ON CONFLICT (hostname) DO UPDATE SET customer_id = EXCLUDED.customer_id
    ")->execute(['hostname' => $hostname, 'cid' => $customerId]);
}

/**
 * Remove manual override
 */
function removeCustomerOverride(string $hostname): void
{
    $db = getDb();
    $db->prepare("DELETE FROM vm_customer_override WHERE hostname = :hostname")
       ->execute(['hostname' => $hostname]);
}

/**
 * Resolve customer_id for a hostname:
 * 1. Manual override (highest priority)
 * 2. Auto-detect from hostname pattern
 * Returns customer_id or null
 */
function resolveCustomerId(string $hostname, array $overrideMap, array $codeMap): ?int
{
    $upper = strtoupper($hostname);

    // Check manual override first
    if (isset($overrideMap[$upper])) {
        return $overrideMap[$upper];
    }

    // Auto-detect from hostname pattern
    $code = extractCustomerCode($hostname);
    if ($code !== null && isset($codeMap[$code])) {
        return $codeMap[$code];
    }

    return null;
}

/**
 * Run auto-assignment: update customer_id on all VMs that don't have one set yet
 * or that can be resolved via pattern/override.
 */
function assignCustomersToVMs(?string $month = null): int
{
    $db = getDb();
    $codeMap = getCustomerCodeMap();
    $overrideMap = getOverrideMap();

    $query = "SELECT id, hostname FROM vm WHERE 1=1";
    $params = [];
    if ($month) {
        $query .= " AND export_month = :month";
        $params['month'] = $month;
    }

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $vms = $stmt->fetchAll();

    $updateStmt = $db->prepare("UPDATE vm SET customer_id = :cid WHERE id = :id AND (customer_id IS DISTINCT FROM :cid2)");
    $updated = 0;

    foreach ($vms as $vm) {
        $cid = resolveCustomerId($vm['hostname'], $overrideMap, $codeMap);
        if ($cid !== null) {
            $updateStmt->execute(['cid' => $cid, 'id' => $vm['id'], 'cid2' => $cid]);
            if ($updateStmt->rowCount() > 0) $updated++;
        }
    }

    return $updated;
}
