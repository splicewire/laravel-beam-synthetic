<?php

namespace Splicewire\Beam\Synthetic\Projection;

use InvalidArgumentException;
use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;

/**
 * What a projection starts from: every party's revenue in one closed month, the anchor. D13: each party's anchor
 * month is held flat, and the assumptions move it.
 *
 * Built from the rows any ledger produces ({@see PartyPeriodRevenue}), so it does not care who measured them: the
 * live ledger through the package's baseline loader, a board source's `revenue([$anchor])`, or rows made by hand.
 * Two rows for one party are summed, as the aggregator sums them; two rows naming different plans are refused,
 * because the projection prices a party on one plan. Rows are kept in party-id order, so the order they arrive in
 * never changes a projection.
 *
 * A party is **paying** when its recurring revenue is at least one whole cent, the aggregator's definition of a logo.
 * Only paying parties count toward churn, growth and the average new logo; a non-paying party (a free plan, say) is
 * carried forward flat.
 */
final class ProjectionBaseline
{
    /**
     * @param  list<PartyPeriodRevenue>  $rows  one per party, ordered by party id
     */
    private function __construct(
        public readonly string $anchor,
        public readonly array $rows,
    ) {}

    /**
     * @param  string  $anchor  the closed month the rows are for, `YYYY-MM`
     * @param  iterable<PartyPeriodRevenue>  $rows  that month's rows; a row for any other month is refused
     */
    public static function fromRows(string $anchor, iterable $rows): self
    {
        ProjectionMonth::assert($anchor, 'anchor month');

        /** @var array<string, array{plan: ?string, recurring: int, usage: int}> $parties */
        $parties = [];

        foreach ($rows as $row) {
            if (! $row instanceof PartyPeriodRevenue) {
                throw new InvalidArgumentException('A projection baseline is built from PartyPeriodRevenue rows.');
            }
            if ($row->period !== $anchor) {
                throw new InvalidArgumentException("Baseline row for {$row->partyId} is in {$row->period}, not the anchor month {$anchor}.");
            }

            $held = $parties[$row->partyId] ?? null;

            if ($held !== null && $held['plan'] !== $row->planKey) {
                $plans = ($held['plan'] ?? 'none').' and '.($row->planKey ?? 'none');
                throw new InvalidArgumentException("Baseline party {$row->partyId} has two plans in {$anchor} ({$plans}); a projection prices a party on one.");
            }

            $parties[$row->partyId] = [
                'plan' => $row->planKey,
                'recurring' => ($held['recurring'] ?? 0) + self::cents($row->recurringUsd),
                'usage' => ($held['usage'] ?? 0) + self::cents($row->usageUsd),
            ];
        }

        ksort($parties, SORT_STRING);

        $normalized = [];
        foreach ($parties as $partyId => $party) {
            $normalized[] = new PartyPeriodRevenue(
                partyId: (string) $partyId,
                period: $anchor,
                planKey: $party['plan'],
                recurringUsd: $party['recurring'] / 100,
                usageUsd: $party['usage'] / 100,
            );
        }

        return new self($anchor, $normalized);
    }

    /** A baseline with no parties: a projection of it is empty unless the assumptions price a new logo. */
    public static function empty(string $anchor): self
    {
        return self::fromRows($anchor, []);
    }

    /** @return list<PartyPeriodRevenue> the parties paying at the anchor, in party-id order */
    public function paying(): array
    {
        return array_values(array_filter($this->rows, fn (PartyPeriodRevenue $row): bool => self::cents($row->recurringUsd) > 0));
    }

    /** Average recurring revenue per paying party at the anchor, in USD (whole cents, rounded); 0.0 with none. */
    public function averageRecurringUsd(): float
    {
        return self::averageCents(array_map(fn (PartyPeriodRevenue $row): int => self::cents($row->recurringUsd), $this->paying())) / 100;
    }

    /** Average usage per paying party at the anchor, in USD (whole cents, rounded); 0.0 with none. */
    public function averageUsageUsd(): float
    {
        return self::averageCents(array_map(fn (PartyPeriodRevenue $row): int => self::cents($row->usageUsd), $this->paying())) / 100;
    }

    /** @param  list<int>  $cents */
    private static function averageCents(array $cents): int
    {
        return $cents === [] ? 0 : (int) round(array_sum($cents) / count($cents));
    }

    private static function cents(float $usd): int
    {
        return (int) round($usd * 100);
    }
}
