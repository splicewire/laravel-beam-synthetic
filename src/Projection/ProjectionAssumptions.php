<?php

namespace Splicewire\Beam\Synthetic\Projection;

use InvalidArgumentException;

/**
 * How a baseline moves forward, month by month, for `horizonMonths` months after the anchor. Every rate is monthly.
 *
 *  - `churnRate` (0 to 1): the share of paying parties lost each month. 1.0 loses all of them in the first month.
 *  - `expansionRate` (-1 or more): each remaining party's recurring revenue changes by this share each month. A
 *    negative rate is contraction; -1.0 takes every party to zero, which the aggregator reads as churn.
 *  - `growthRate`: new paying parties each month, as a share of the paying parties at the end of the month before.
 *    **A negative growth rate floors at zero new parties**: losing parties is churn's job, so growth never removes
 *    one. Growth is relative to the book, so a book with no paying parties gains none.
 *  - `priceChanges`: list-price changes from a projected month on ({@see PriceChange}). A change at or before the
 *    anchor is refused by the engine: the anchor's prices are already in the baseline.
 *  - `newLogoRecurringUsd`: what a new party pays per month before price changes; null means the baseline's average
 *    paying party. `newLogoPlanKey` is the plan it is on (null: none, so only an all-plan price change reaches it).
 *
 * D13's portfolio rates map straight on: a portfolio's `churnRate` and `expansionRate` are these two.
 */
final class ProjectionAssumptions
{
    /**
     * @param  list<PriceChange>  $priceChanges
     */
    public function __construct(
        public readonly int $horizonMonths,
        public readonly float $churnRate = 0.0,
        public readonly float $expansionRate = 0.0,
        public readonly float $growthRate = 0.0,
        public readonly array $priceChanges = [],
        public readonly ?float $newLogoRecurringUsd = null,
        public readonly ?string $newLogoPlanKey = null,
    ) {
        if ($horizonMonths < 0) {
            throw new InvalidArgumentException("A projection horizon is 0 or more months; got {$horizonMonths}.");
        }
        if (! is_finite($churnRate) || $churnRate < 0.0 || $churnRate > 1.0) {
            throw new InvalidArgumentException("The churn rate must be a fraction from 0 to 1; got {$churnRate}.");
        }
        if (! is_finite($expansionRate) || $expansionRate < -1.0) {
            throw new InvalidArgumentException("The expansion rate must be a finite rate of at least -1; got {$expansionRate}.");
        }
        if (! is_finite($growthRate)) {
            throw new InvalidArgumentException('The growth rate must be finite.');
        }
        if ($newLogoRecurringUsd !== null && (! is_finite($newLogoRecurringUsd) || $newLogoRecurringUsd < 0.0)) {
            throw new InvalidArgumentException("A new party's recurring revenue must be 0 or more; got {$newLogoRecurringUsd}.");
        }
        foreach ($priceChanges as $i => $change) {
            if (! $change instanceof PriceChange) {
                throw new InvalidArgumentException("Price change {$i} is not a PriceChange.");
            }
        }
    }

    /** The growth rate as the engine applies it: never below zero. */
    public function acquisitionRate(): float
    {
        return max(0.0, $this->growthRate);
    }
}
