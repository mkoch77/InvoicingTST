<?php

require_once __DIR__ . '/db.php';

/**
 * Load point factors from DB
 * Returns ['vcpu' => 17.5185, 'vram_gb' => 7.2807, 'storage_gb' => 0.1668]
 */
function getPricingFactors(): array
{
    static $factors = null;
    if ($factors !== null) return $factors;

    $db = getDb();
    $stmt = $db->query("SELECT resource, points_per_unit FROM pricing_factor");
    $factors = [];
    foreach ($stmt->fetchAll() as $row) {
        $factors[$row['resource']] = (float) $row['points_per_unit'];
    }
    return $factors;
}

/**
 * Load pricing tiers sorted by max_points ascending
 */
function getPricingTiers(): array
{
    static $tiers = null;
    if ($tiers !== null) return $tiers;

    $db = getDb();
    $stmt = $db->query("SELECT * FROM pricing_tier ORDER BY sort_order ASC");
    $tiers = $stmt->fetchAll();
    return $tiers;
}

/**
 * Calculate points for a VM
 */
function calculatePoints(int $vcpu, int $vramMb, float $provisionedStorageGb): float
{
    $f = getPricingFactors();
    $vramGb = $vramMb / 1024.0;

    return ($vcpu * ($f['vcpu'] ?? 17.5185))
         + ($vramGb * ($f['vram_gb'] ?? 7.2807))
         + ($provisionedStorageGb * ($f['storage_gb'] ?? 0.1668));
}

/**
 * Find the pricing tier for a given point value.
 * Returns the first tier where max_points >= points (next higher class).
 * If points exceed all tiers, returns the highest tier.
 */
function findTier(float $points): ?array
{
    $tiers = getPricingTiers();

    foreach ($tiers as $tier) {
        if ($points <= (float) $tier['max_points']) {
            return $tier;
        }
    }

    // Points exceed all tiers: return highest
    return !empty($tiers) ? end($tiers) : null;
}

/**
 * Enrich a VM row with pricing data
 */
function enrichVmWithPricing(array &$vm): void
{
    $vcpu = (int) ($vm['vcpu'] ?? 0);
    $vramMb = (int) ($vm['vram_mb'] ?? 0);
    $provStorageGb = (float) ($vm['provisioned_storage_gb'] ?? 0);

    $points = calculatePoints($vcpu, $vramMb, $provStorageGb);
    $tier = findTier($points);

    $vm['points'] = round($points, 2);
    $vm['pricing_class'] = $tier ? $tier['class_name'] : null;
    $vm['price'] = $tier ? (float) $tier['price'] : null;
}

/**
 * List all pricing tiers (for settings/admin)
 */
function listPricingTiers(): array
{
    return getPricingTiers();
}

/**
 * List all pricing factors (for settings/admin)
 */
function listPricingFactors(): array
{
    $db = getDb();
    $stmt = $db->query("SELECT * FROM pricing_factor ORDER BY resource");
    return $stmt->fetchAll();
}
