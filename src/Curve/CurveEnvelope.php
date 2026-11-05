<?php

namespace Splicewire\Beam\Synthetic\Curve;

use InvalidArgumentException;

/**
 * The kernel's whole input apart from the seed: a shape, the periods to expand, a scalar target, an
 * integer ramp and an optional cutoff.
 *
 * - `scalarTarget` is the value the scalar series reaches (`SteadyRamp`) or peaks toward (`Seasonal`,
 *   `DipThenRecover`).
 * - `rampFrom`/`rampTo` bound the integer series, which rises from one to the other.
 * - `cutoffPeriod` is the 0-based index of the last live period. Every period after it is zeroed in
 *   both series. `null` means no cutoff; `-1` means the cutoff falls before the first period, so every
 *   period is zeroed. The cutoff period itself stays live.
 * - `cycleLength` is the number of periods in one `Seasonal` cycle (12 for months in a year).
 *
 * A plain value object, with no framework type, so a caller of any tier can build one.
 */
final class CurveEnvelope
{
    /** @var list<CurvePeriod> */
    public readonly array $periods;

    /**
     * @param  list<CurvePeriod>  $periods  in order; the kernel returns one point per period, in this order
     */
    public function __construct(
        public readonly GrowthShape $shape,
        array $periods,
        public readonly float $scalarTarget,
        public readonly int $rampFrom,
        public readonly int $rampTo,
        public readonly ?int $cutoffPeriod = null,
        public readonly int $cycleLength = 12,
    ) {
        if (! array_is_list($periods)) {
            throw new InvalidArgumentException('A curve envelope takes its periods as an ordered list.');
        }

        foreach ($periods as $i => $period) {
            if (! $period instanceof CurvePeriod) {
                throw new InvalidArgumentException("Curve period {$i} is not a ".CurvePeriod::class.'.');
            }
        }

        if ($cycleLength < 1) {
            throw new InvalidArgumentException("A curve envelope's cycle length must be at least 1; got {$cycleLength}.");
        }

        if ($cutoffPeriod !== null && $cutoffPeriod < -1) {
            throw new InvalidArgumentException("A curve envelope's cutoff is a period index or -1; got {$cutoffPeriod}.");
        }

        $this->periods = $periods;
    }
}
