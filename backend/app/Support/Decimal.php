<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/** Positive fixed-point quantities and currency; never calculate totals with floats. */
class Decimal
{
    public static function scaled($value, int $places): int
    {
        $value = (string) $value;
        if (! preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
            throw ValidationException::withMessages(['items' => 'Gunakan angka desimal positif dengan titik sebagai pemisah.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) ($whole.str_pad(substr($fraction, 0, $places), $places, '0'));
    }

    public static function rounded(int $value, int $divisor): int
    {
        return intdiv($value + intdiv($divisor, 2), $divisor);
    }

    public static function format(int $value, int $places = 2): string
    {
        $digits = str_pad((string) $value, $places + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$places).'.'.substr($digits, -$places);
    }
}
