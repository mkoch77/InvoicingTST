<?php
/**
 * Device pricing: classify devices by manufacturer/model and assign pricing.
 */

require_once __DIR__ . '/db.php';

function getDevicePricingTiers(): array
{
    $db = getDb();
    return $db->query("SELECT * FROM device_pricing WHERE is_active = TRUE ORDER BY sort_order")->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Classify a device based on manufacturer and model.
 * Returns ['category' => string, 'price' => float] or null.
 */
function classifyDevice(string $manufacturer, string $model, ?array $tiers = null): ?array
{
    if ($tiers === null) {
        $tiers = getDevicePricingTiers();
    }

    $mfr = strtolower(trim($manufacturer));
    $mdl = strtolower(trim($model));

    // Priority matching (order matters!)

    // Docks first (before general manufacturer matches)
    if (str_contains($mdl, 'dock') || str_contains($mdl, 'docking')) {
        if (str_contains($mfr, 'hp') || str_contains($mfr, 'hewlett')) {
            return findTier($tiers, 'HP Dock');
        }
        return findTier($tiers, 'Alt Dock');
    }

    // Surface Hub (before generic Surface)
    if (str_contains($mfr, 'microsoft') && str_contains($mdl, 'surface hub')) {
        if (str_contains($mdl, '85')) return findTier($tiers, 'Surface Hub 85');
        if (str_contains($mdl, '50')) return findTier($tiers, 'Surface Hub 50');
        return findTier($tiers, 'Surface Hub 85'); // Default for unknown Hub size
    }

    // Surface Pro (before generic Surface)
    if (str_contains($mfr, 'microsoft') && str_contains($mdl, 'surface pro')) {
        return findTier($tiers, 'Surface Pro');
    }

    // Surface Laptop / Surface Go / generic Surface
    if (str_contains($mfr, 'microsoft') && str_contains($mdl, 'surface')) {
        return findTier($tiers, 'Surface');
    }

    // Desktop
    if (str_contains($mdl, 'desktop') || str_contains($mdl, 'optiplex') ||
        str_contains($mdl, 'tower') || str_contains($mdl, 'mini pc') ||
        str_contains($mdl, 'prodesk') || str_contains($mdl, 'elitedesk') ||
        str_contains($mdl, 'thinkcentre')) {
        return findTier($tiers, 'Desktop');
    }

    // HP Laptops by screen size
    if (str_contains($mfr, 'hp') || str_contains($mfr, 'hewlett')) {
        // Try to detect screen size from model name
        if (preg_match('/\b(15|16)\b/', $mdl) || str_contains($mdl, '16') ||
            str_contains($mdl, '850') || str_contains($mdl, '860') ||
            str_contains($mdl, '1040') || str_contains($mdl, '1050')) {
            return findTier($tiers, 'HP 16 Zoll');
        }
        if (preg_match('/\b(13|14)\b/', $mdl) || str_contains($mdl, '14') ||
            str_contains($mdl, '640') || str_contains($mdl, '645') ||
            str_contains($mdl, '840') || str_contains($mdl, '845')) {
            return findTier($tiers, 'HP 14 Zoll');
        }
        // HP but unknown size → default to 14"
        return findTier($tiers, 'HP 14 Zoll');
    }

    // Lenovo, Dell, other known manufacturers → Altgeräte
    if ($mfr && $mdl) {
        return findTier($tiers, 'Altgeräte');
    }

    return null;
}

function findTier(array $tiers, string $categoryName): ?array
{
    foreach ($tiers as $t) {
        if ($t['category_name'] === $categoryName) {
            return ['category' => $t['category_name'], 'price' => (float) $t['price']];
        }
    }
    return null;
}

/**
 * Enrich a device array with pricing info.
 */
function enrichDeviceWithPricing(array &$device, ?array $tiers = null): void
{
    $result = classifyDevice($device['manufacturer'] ?? '', $device['model'] ?? '', $tiers);
    if ($result) {
        $device['device_category'] = $result['category'];
        $device['device_price'] = $result['price'];
    } else {
        $device['device_category'] = null;
        $device['device_price'] = 0;
    }
}
