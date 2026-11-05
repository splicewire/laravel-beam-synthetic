<?php

namespace Splicewire\Beam\Synthetic\Projection;

use InvalidArgumentException;

/**
 * A list-price change from one projected month on: every recurring amount on the plan (or on every plan, when
 * `planKey` is null) is multiplied by `1 + rate` from `period`, and stays changed after it. A rate of 0.10 is a 10%
 * rise; -0.10 a 10% cut; -1.0 makes the plan free, which the aggregator reads as churn of its paying parties.
 */
final class PriceChange
{
    public function __construct(
        public readonly string $period,
        public readonly float $rate,
        public readonly ?string $planKey = null,
    ) {
        ProjectionMonth::assert($period, 'price-change month');

        if (! is_finite($rate) || $rate < -1.0) {
            throw new InvalidArgumentException("A price change in {$period} must be a finite rate of at least -1 (free); got {$rate}.");
        }
    }

    public function appliesTo(?string $planKey): bool
    {
        return $this->planKey === null || $this->planKey === $planKey;
    }
}
