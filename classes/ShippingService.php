<?php
declare(strict_types=1);

class ShippingService
{
    // Rates per region (you can expand this)
    private const REGIONAL_RATES = [
        'NCR'         => 150.00,
        'LUZON'       => 180.00,
        'VISAYAS'     => 220.00,
        'MINDANAO'    => 250.00,
        'DEFAULT'     => 200.00,
    ];

    // Weight surcharge per kg above 1kg
    private const WEIGHT_SURCHARGE = 20.00;
    private const BASE_WEIGHT_KG   = 1.0;

    /**
     * Calculate shipping fee based on province and total weight.
     */
    public static function calculate(string $province, float $weightKg = 1.0): float
    {
        $region   = self::getRegionFromProvince($province);
        $baseRate = self::REGIONAL_RATES[$region] ?? self::REGIONAL_RATES['DEFAULT'];

        // Weight surcharge for orders over 1kg
        $extraWeight = max(0, $weightKg - self::BASE_WEIGHT_KG);
        $surcharge   = ceil($extraWeight) * self::WEIGHT_SURCHARGE;

        return round($baseRate + $surcharge, 2);
    }

    /**
     * Map province name to region.
     * This covers major Philippine provinces.
     */
    private static function getRegionFromProvince(string $province): string
    {
        $province = strtolower(trim($province));

        $ncrKeywords = ['metro manila', 'manila', 'quezon city', 'makati',
                        'pasig', 'taguig', 'mandaluyong', 'marikina',
                        'caloocan', 'las piñas', 'muntinlupa', 'parañaque',
                        'pasay', 'navotas', 'malabon', 'valenzuela', 'pateros'];

        $visayasProvinces = ['cebu', 'bohol', 'iloilo', 'negros occidental',
                             'negros oriental', 'leyte', 'samar', 'aklan',
                             'capiz', 'antique', 'guimaras', 'biliran',
                             'eastern samar', 'northern samar', 'western samar',
                             'southern leyte', 'siquijor'];

        $mindanaoProvinces = ['davao', 'bukidnon', 'misamis oriental',
                              'misamis occidental', 'zamboanga', 'cotabato',
                              'sultan kudarat', 'sarangani', 'south cotabato',
                              'maguindanao', 'lanao', 'agusan', 'surigao',
                              'camiguin', 'dinagat', 'basilan', 'sulu', 'tawi-tawi'];

        foreach ($ncrKeywords as $k) {
            if (str_contains($province, $k)) return 'NCR';
        }

        foreach ($visayasProvinces as $p) {
            if (str_contains($province, $p)) return 'VISAYAS';
        }

        foreach ($mindanaoProvinces as $p) {
            if (str_contains($province, $p)) return 'MINDANAO';
        }

        return 'LUZON'; // Default for unlisted Luzon provinces
    }

    /**
     * Get all available rates for display on checkout page.
     */
    public static function getAllRates(): array
    {
        return self::REGIONAL_RATES;
    }
}