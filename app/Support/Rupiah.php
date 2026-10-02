<?php

namespace App\Support;

/**
 * Short Indonesian rupiah labels for the model prompt, e.g. 329856 -> "Rp329 rb",
 * 1250000 -> "Rp1,25 jt". Money is integer rupiah everywhere; this is display only.
 */
class Rupiah
{
    public static function short(int $amount): string
    {
        $amount = (int) $amount;

        if ($amount >= 1_000_000) {
            $value = $amount / 1_000_000;
            $formatted = rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');

            return 'Rp'.$formatted.' jt';
        }

        if ($amount >= 1_000) {
            return 'Rp'.number_format(intdiv($amount, 1_000), 0, ',', '.').' rb';
        }

        return 'Rp'.number_format($amount, 0, ',', '.');
    }

    /**
     * @param  array<string, int>  $totals
     * @return array<string, string>
     */
    public static function shortMap(array $totals): array
    {
        return collect($totals)
            ->map(fn ($value) => self::short((int) $value))
            ->all();
    }
}
