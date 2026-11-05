<?php

namespace Splicewire\Beam\Synthetic\Curve;

/**
 * One period's value on a curve: the period's key, the scalar series (rounded to 2 places) and the
 * integer series.
 */
final class CurvePoint
{
    public function __construct(
        public readonly string $period,
        public readonly float $scalar,
        public readonly int $integer,
    ) {}
}
