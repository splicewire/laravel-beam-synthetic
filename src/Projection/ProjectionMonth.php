<?php

namespace Splicewire\Beam\Synthetic\Projection;

use InvalidArgumentException;

/**
 * Calendar-month keys (`YYYY-MM`) for the projection engine, without a clock or a date library: the engine names no
 * framework type, so it does its own month arithmetic on the key.
 */
final class ProjectionMonth
{
    public static function assert(string $month, string $what = 'month'): string
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException("Invalid {$what} '{$month}'. Expected YYYY-MM.");
        }

        return $month;
    }

    /** The month `$n` months after `$month` (0 = itself). */
    public static function after(string $month, int $n = 1): string
    {
        [$year, $m] = array_map('intval', explode('-', self::assert($month)));
        $index = $year * 12 + ($m - 1) + $n;

        return sprintf('%04d-%02d', intdiv($index, 12), $index % 12 + 1);
    }
}
