<?php

namespace Splicewire\Beam\Synthetic\Baseline;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Rushing\Commerce\Billing\BillingPeriod;
use Splicewire\Beam\Commerce\Revenue\MeasuredRevenueLedger;
use Splicewire\Beam\Synthetic\Projection\ProjectionBaseline;
use Splicewire\Beam\Synthetic\Projection\ProjectionEngine;

/**
 * Seeds the projection engine from the host's own books: the live ledger's rows for one closed month, as a
 * {@see ProjectionBaseline}. This is the only part of a projection that reads the database; the engine
 * ({@see ProjectionEngine}) reads nothing.
 *
 * The anchor must be closed, a month before the current billing month: an open month's measures are still moving,
 * and the ledger reads nothing after the current month, so a later anchor would project an empty book.
 *
 * A caller that already holds ledger-shaped rows (a board source's `revenue([$anchor])`, for instance) skips this
 * loader and calls {@see ProjectionBaseline::fromRows()} itself. This package may not reach the board package, which
 * requires it, so the loader names the ledger, not a board source.
 */
final class MeasuredBaselineLoader
{
    public function __construct(private readonly MeasuredRevenueLedger $ledger) {}

    /**
     * @param  string  $anchor  a closed month, `YYYY-MM`
     * @param  list<string>|null  $partyIds  only these parties; null = every party with a plan subscription
     */
    public function load(string $anchor, ?array $partyIds = null): ProjectionBaseline
    {
        $anchor = (string) BillingPeriod::fromString($anchor);
        $current = (string) BillingPeriod::current();

        if ($anchor >= $current) {
            throw new InvalidArgumentException("A projection baseline is a closed month; {$anchor} is not before the current month {$current}.");
        }

        return ProjectionBaseline::fromRows($anchor, $this->ledger->read([$anchor], $partyIds));
    }

    /**
     * The last closed month as of `$asOf` (the month before its month), as D13 holds it flat.
     *
     * @param  list<string>|null  $partyIds
     */
    public function lastClosed(CarbonImmutable $asOf, ?array $partyIds = null): ProjectionBaseline
    {
        return $this->load($asOf->startOfMonth()->subMonthNoOverflow()->format('Y-m'), $partyIds);
    }
}
