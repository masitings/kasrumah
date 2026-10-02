<?php

namespace App\Support;

class Categories
{
    public const ALL = [
        'dapur' => 'Belanja dapur (sayur, lauk, bumbu, beras)',
        'jajan' => 'Jajan & makan di luar',
        'online' => 'Belanja online (Shopee, Tokopedia, dll)',
        'rumah' => 'Kebutuhan rumah (sabun, gas, galon, perabot)',
        'tagihan' => 'Tagihan (listrik, air, internet, pulsa)',
        'transport' => 'Transport (bensin, ojol, parkir)',
        'kesehatan' => 'Kesehatan & obat',
        'keluarga' => 'Keluarga & sosial (kondangan, kiriman)',
        'lainnya' => 'Lainnya',
    ];

    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function forPrompt(): string
    {
        return collect(self::ALL)
            ->map(fn ($label, $key) => "- {$key}: {$label}")
            ->implode("\n");
    }
}
