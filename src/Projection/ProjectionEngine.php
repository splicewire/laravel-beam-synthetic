<?php

namespace Splicewire\Beam\Synthetic\Projection;

use InvalidArgumentException;
use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;

/**
 * The financial projection engine: a baseline ({@see ProjectionBaseline}) and assumptions
 * ({@see ProjectionAssumptions}) in, projected monthly revenue rows ({@see RevenueProjection}) out, in the shape
 * beam-commerce's `RevenueMovements` aggregates for the live ledger too. One aggregator reads real and projected
 * numbers, so they cannot drift apart.
 *
 * Pure: no clock, no config, no container, no query. It imports nothing but its own namespace, PHP's
 * `InvalidArgumentException` and commerce's `PartyPeriodRevenue` (itself import-free);
 * `tests/Projection/ProjectionEnginePurityTest.php` reads the `use` lines and holds it there. The same inputs give
 * byte-identical output.
 *
 * Each projected month, in this order:
 *
 *  1. **Churn.** `churnRate` × the paying parties at the end of the month before is added to a running remainder,
 *     and that many whole parties leave. They leave in a fixed order: the baseline's parties by party id, then new
 *     parties in the order they arrived. Logo churn is exact to the rate over time; which party goes is arbitrary
 *     but reproducible, so revenue churn follows the parties picked.
 *  2. **Expansion.** Every remaining party's recurring revenue is multiplied by `1 + expansionRate`.
 *  3. **Price changes** effective this month multiply the recurring revenue of the parties on their plan.
 *  4. **Growth.** `growthRate` (floored at zero) × the same paying count from step 1 is added to its own running
 *     remainder, and that many new parties arrive, named `projected-0001`, `projected-0002` and so on. Each pays
 *     `newLogoRecurringUsd` (default: the baseline's average paying party) with every price change in force so far
 *     on its plan applied, and uses the baseline's average usage per paying party.
 *
 * Usage is held flat per party (D13), and goes with a party that churns. A party whose recurring revenue reaches zero
 * keeps its row at zero, as a party on a free plan does; the aggregator reads that as churn of a logo. A baseline
 * party that was not paying at the anchor is carried forward flat and is never picked to churn.
 *
 * Amounts are carried unrounded between months and rounded to the cent only on the rows emitted.
 */
final class ProjectionEngine
{
    private const NEW_PARTY_PREFIX = 'projected-';

    public function project(ProjectionBaseline $baseline, ProjectionAssumptions $assumptions): RevenueProjection
    {
        foreach ($assumptions->priceChanges as $change) {
            if ($change->period <= $baseline->anchor) {
                throw new InvalidArgumentException("A price change in {$change->period} is not after the anchor month {$baseline->anchor}; the anchor's prices are already in the baseline.");
            }
        }

        /** @var list<array{id: string, plan: ?string, recurring: float, usage: float}> $book */
        $book = array_map(fn (PartyPeriodRevenue $row): array => [
            'id' => $row->partyId,
            'plan' => $row->planKey,
            'recurring' => $row->recurringUsd,
            'usage' => $row->usageUsd,
        ], $baseline->rows);

        $known = array_fill_keys(array_column($book, 'id'), true);
        $newRecurring = $assumptions->newLogoRecurringUsd ?? $baseline->averageRecurringUsd();
        $newUsage = $baseline->averageUsageUsd();
        $newPriceFactor = 1.0;
        $churnCarry = $growthCarry = 0.0;
        $arrived = 0;

        $periods = [];
        $rows = [];

        for ($step = 1; $step <= $assumptions->horizonMonths; $step++) {
            $period = ProjectionMonth::after($baseline->anchor, $step);
            $periods[] = $period;

            $payingBefore = count(array_filter($book, fn (array $party): bool => self::paying($party['recurring'])));

            // 1. Churn.
            $leaving = self::draw($churnCarry, $assumptions->churnRate * $payingBefore, $payingBefore);
            foreach ($book as $i => $party) {
                if ($leaving === 0) {
                    break;
                }
                if (self::paying($party['recurring'])) {
                    unset($book[$i]);
                    $leaving--;
                }
            }
            $book = array_values($book);

            // 2. Expansion, then 3. price changes in force from this month.
            $changes = array_values(array_filter($assumptions->priceChanges, fn (PriceChange $c): bool => $c->period === $period));

            foreach ($book as $i => $party) {
                $recurring = $party['recurring'] * (1.0 + $assumptions->expansionRate);

                foreach ($changes as $change) {
                    if ($change->appliesTo($party['plan'])) {
                        $recurring *= 1.0 + $change->rate;
                    }
                }

                $book[$i]['recurring'] = max(0.0, $recurring);
            }

            foreach ($changes as $change) {
                if ($change->appliesTo($assumptions->newLogoPlanKey)) {
                    $newPriceFactor *= 1.0 + $change->rate;
                }
            }

            // 4. Growth.
            $arriving = self::draw($growthCarry, $assumptions->acquisitionRate() * $payingBefore, PHP_INT_MAX);
            for ($n = 0; $n < $arriving; $n++) {
                $id = sprintf('%s%04d', self::NEW_PARTY_PREFIX, ++$arrived);

                if (isset($known[$id])) {
                    throw new InvalidArgumentException("The baseline already has a party named {$id}; the projection names its new parties that way.");
                }

                $book[] = ['id' => $id, 'plan' => $assumptions->newLogoPlanKey, 'recurring' => max(0.0, $newRecurring * $newPriceFactor), 'usage' => $newUsage];
            }

            $emitted = $book;
            usort($emitted, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

            foreach ($emitted as $party) {
                $rows[] = new PartyPeriodRevenue(
                    partyId: $party['id'],
                    period: $period,
                    planKey: $party['plan'],
                    recurringUsd: round($party['recurring'], 2),
                    usageUsd: round($party['usage'], 2),
                );
            }
        }

        return new RevenueProjection($baseline->anchor, $periods, $baseline->rows, $rows);
    }

    /**
     * Adds `$expected` to a running remainder and takes the whole parties out of it, at most `$cap`. The tolerance
     * keeps a product such as 0.29 × 100 (28.999…) from losing a party to binary rounding.
     */
    private static function draw(float &$carry, float $expected, int $cap): int
    {
        $carry += $expected;
        $whole = min($cap, (int) floor($carry + 1e-9));
        $carry = max(0.0, $carry - $whole);

        return $whole;
    }

    private static function paying(float $recurringUsd): bool
    {
        return (int) round($recurringUsd * 100) > 0;
    }
}
