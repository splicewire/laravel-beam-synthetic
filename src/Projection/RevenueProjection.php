<?php

namespace Splicewire\Beam\Synthetic\Projection;

use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;

/**
 * A projection's result: the anchor month's baseline rows followed by one row per party per projected month, in the
 * shape every ledger shares. Hand {@see periods()} and {@see rows()} to beam-commerce's `RevenueMovements::from()`
 * unchanged: the anchor is the opening month, so the first projected month already has a movement.
 *
 * A party with no row in a projected month has churned. `billedUsd` and `paidUsd` are always null: nothing projected
 * is billed.
 */
final class RevenueProjection
{
    /**
     * @param  list<string>  $projectedPeriods  the months after the anchor, ascending
     * @param  list<PartyPeriodRevenue>  $baselineRows
     * @param  list<PartyPeriodRevenue>  $projectedRows  month by month, each month in party order
     */
    public function __construct(
        public readonly string $anchor,
        private readonly array $projectedPeriods,
        private readonly array $baselineRows,
        private readonly array $projectedRows,
    ) {}

    /** @return list<string> the anchor, then every projected month */
    public function periods(): array
    {
        return [$this->anchor, ...$this->projectedPeriods];
    }

    /** @return list<PartyPeriodRevenue> the baseline rows, then the projected rows */
    public function rows(): array
    {
        return [...$this->baselineRows, ...$this->projectedRows];
    }

    /** @return list<string> */
    public function projectedPeriods(): array
    {
        return $this->projectedPeriods;
    }

    /** @return list<PartyPeriodRevenue> */
    public function projectedRows(): array
    {
        return $this->projectedRows;
    }

    /**
     * A plain, ordered rendering of the whole projection, for comparing two runs byte for byte.
     *
     * @return array{anchor: string, periods: list<string>, rows: list<array{party: string, period: string, plan: ?string, recurringUsd: float, usageUsd: float}>}
     */
    public function toArray(): array
    {
        return [
            'anchor' => $this->anchor,
            'periods' => $this->periods(),
            'rows' => array_map(fn (PartyPeriodRevenue $row): array => [
                'party' => $row->partyId,
                'period' => $row->period,
                'plan' => $row->planKey,
                'recurringUsd' => $row->recurringUsd,
                'usageUsd' => $row->usageUsd,
            ], $this->rows()),
        ];
    }
}
