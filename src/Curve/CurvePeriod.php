<?php

namespace Splicewire\Beam\Synthetic\Curve;

use InvalidArgumentException;

/**
 * One period of a curve: the caller's key for it, and its position in the cycle the `Seasonal` shape
 * follows.
 *
 * The kernel never parses `key`. It is carried through to the matching {@see CurvePoint} so the caller
 * can line points up with its own calendar. `phase` is a 0-based position within a cycle of the
 * envelope's `cycleLength` periods: for monthly periods on a yearly cycle, January is 0 and December is
 * 11. Only `Seasonal` reads it.
 */
final class CurvePeriod
{
    public function __construct(
        public readonly string $key,
        public readonly int $phase = 0,
    ) {
        if ($phase < 0) {
            throw new InvalidArgumentException("A curve period's phase is 0-based; got {$phase} for '{$key}'.");
        }
    }
}
