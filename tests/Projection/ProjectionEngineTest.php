<?php

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;
use Splicewire\Beam\Commerce\Revenue\RevenueMovements;
use Splicewire\Beam\Synthetic\Projection\PriceChange;
use Splicewire\Beam\Synthetic\Projection\ProjectionAssumptions;
use Splicewire\Beam\Synthetic\Projection\ProjectionBaseline;
use Splicewire\Beam\Synthetic\Projection\ProjectionEngine;
use Splicewire\Beam\Synthetic\Projection\ProjectionMonth;
use Splicewire\Beam\Synthetic\Projection\RevenueProjection;

/*
 * The projection engine, pure. Every test here runs inside `DB::listen` and must make zero queries; the last test
 * proves the listener hears one.
 */

beforeEach(function () {
    $this->queries = 0;
    $this->expectedQueries = 0;
    DB::listen(function (): void {
        $this->queries++;
    });
});

afterEach(function () {
    expect($this->queries)->toBe($this->expectedQueries);
});

const ANCHOR = '2026-10';

function rev(string $party, float $recurring, ?string $plan = 'pro', float $usage = 0.0, string $period = ANCHOR): PartyPeriodRevenue
{
    return new PartyPeriodRevenue(partyId: $party, period: $period, planKey: $plan, recurringUsd: $recurring, usageUsd: $usage);
}

/** @param  list<PartyPeriodRevenue>  $rows */
function projectRows(array $rows, ProjectionAssumptions $assumptions): RevenueProjection
{
    return (new ProjectionEngine)->project(ProjectionBaseline::fromRows(ANCHOR, $rows), $assumptions);
}

function movementsOf(RevenueProjection $projection): RevenueMovements
{
    return RevenueMovements::from($projection->periods(), $projection->rows());
}

/** @return array<string, list<string>> month => paying party ids */
function payingByMonth(RevenueProjection $projection): array
{
    $byMonth = array_fill_keys($projection->projectedPeriods(), []);
    foreach ($projection->projectedRows() as $row) {
        if (round($row->recurringUsd * 100) > 0) {
            $byMonth[$row->period][] = $row->partyId;
        }
    }

    return $byMonth;
}

/** @return list<float> MRR at each projected month */
function projectedMrr(RevenueProjection $projection): array
{
    $movements = movementsOf($projection);

    return array_map(fn (string $p): float => $movements->mrr($p), $projection->projectedPeriods());
}

// --- The baseline held flat (D13) -----------------------------------------------------------------------------

it('holds every party flat when no assumption moves it', function () {
    $projection = projectRows([rev('a', 100.0, usage: 12.5), rev('b', 250.0, 'team'), rev('c', 40.0)], new ProjectionAssumptions(horizonMonths: 3));

    expect($projection->periods())->toBe(['2026-10', '2026-11', '2026-12', '2027-01'])
        ->and($projection->projectedRows())->toHaveCount(9)
        ->and(projectedMrr($projection))->toBe([390.0, 390.0, 390.0]);

    foreach ($projection->projectedRows() as $row) {
        expect($row->billedUsd)->toBeNull()->and($row->paidUsd)->toBeNull();
    }

    foreach (movementsOf($projection)->movements() as $movement) {
        expect($movement->netNewMrr())->toBe(0.0)
            ->and($movement->churnedLogos)->toBe(0)
            ->and($movement->usageUsd)->toBe(12.5);
    }
});

it('projects nothing at horizon 0, and the aggregator reads the anchor alone', function () {
    $projection = projectRows([rev('a', 100.0), rev('b', 50.0)], new ProjectionAssumptions(horizonMonths: 0, churnRate: 0.5, growthRate: 1.0));

    expect($projection->periods())->toBe([ANCHOR])
        ->and($projection->projectedPeriods())->toBe([])
        ->and($projection->projectedRows())->toBe([])
        ->and($projection->rows())->toHaveCount(2)
        ->and(movementsOf($projection)->mrr(ANCHOR))->toBe(150.0)
        ->and(movementsOf($projection)->movements())->toBe([]);
});

it('projects an empty book from a zero baseline, even with growth and a priced new logo', function () {
    $projection = (new ProjectionEngine)->project(
        ProjectionBaseline::empty(ANCHOR),
        new ProjectionAssumptions(horizonMonths: 6, churnRate: 0.1, expansionRate: 0.2, growthRate: 0.5, newLogoRecurringUsd: 99.0),
    );

    expect($projection->projectedPeriods())->toHaveCount(6)
        ->and($projection->rows())->toBe([])
        ->and(projectedMrr($projection))->toBe([0.0, 0.0, 0.0, 0.0, 0.0, 0.0]);

    foreach (movementsOf($projection)->movements() as $movement) {
        expect($movement->logoChurnRate())->toBeNull()->and($movement->netRevenueRetention())->toBeNull();
    }
});

// --- Churn -----------------------------------------------------------------------------------------------------

it('loses every paying party in the first month at 100% churn, and keeps a non-paying one', function () {
    $projection = projectRows(
        [rev('a', 100.0, usage: 5.0), rev('b', 200.0, usage: 7.0), rev('free', 0.0, 'free', usage: 3.0)],
        new ProjectionAssumptions(horizonMonths: 3, churnRate: 1.0),
    );

    $movements = movementsOf($projection);
    $first = $movements->movement('2026-11');

    expect($first->churnedMrr)->toBe(300.0)
        ->and($first->churnedLogos)->toBe(2)
        ->and($first->logoChurnRate())->toBe(1.0)
        ->and($first->endingMrr)->toBe(0.0)
        ->and($first->newLogos)->toBe(0)
        ->and($first->usageUsd)->toBe(3.0)
        ->and(projectedMrr($projection))->toBe([0.0, 0.0, 0.0])
        ->and(array_map(fn (PartyPeriodRevenue $r): string => $r->partyId, $projection->projectedRows()))->toBe(['free', 'free', 'free']);
});

it('at 100% churn with growth, loses each month\'s whole book and keeps only that month\'s arrivals', function () {
    $projection = projectRows([rev('a', 100.0), rev('b', 200.0)], new ProjectionAssumptions(horizonMonths: 3, churnRate: 1.0, growthRate: 1.0));

    // Both rates read the paying parties at the end of the month before: 2 leave and 2 arrive, every month.
    expect(payingByMonth($projection))->toBe([
        '2026-11' => ['projected-0001', 'projected-0002'],
        '2026-12' => ['projected-0003', 'projected-0004'],
        '2027-01' => ['projected-0005', 'projected-0006'],
    ]);

    foreach (movementsOf($projection)->movements() as $movement) {
        expect($movement->logoChurnRate())->toBe(1.0)->and($movement->newLogos)->toBe(2);
    }
});

it('churns whole parties at the rate over time, in party-id order', function () {
    $rows = array_map(fn (int $i): PartyPeriodRevenue => rev(sprintf('p%02d', $i), 100.0), range(1, 10));
    $projection = projectRows($rows, new ProjectionAssumptions(horizonMonths: 4, churnRate: 0.25));

    $paying = payingByMonth($projection);

    // 10 × 0.25 = 2.5 → 2 (0.5 carried); 8 × 0.25 + 0.5 = 2.5 → 2; 6 × 0.25 + 0.5 = 2 → 2; 4 × 0.25 = 1 → 1.
    expect(array_map('count', $paying))->toBe(['2026-11' => 8, '2026-12' => 6, '2027-01' => 4, '2027-02' => 3])
        ->and($paying['2026-11'])->toBe(['p03', 'p04', 'p05', 'p06', 'p07', 'p08', 'p09', 'p10'])
        ->and($paying['2027-02'])->toBe(['p08', 'p09', 'p10'])
        ->and(movementsOf($projection)->movement('2026-11')->logoChurnRate())->toBe(0.2);
});

it('does not lose a party to binary rounding of the rate (0.29 × 100)', function () {
    $rows = array_map(fn (int $i): PartyPeriodRevenue => rev(sprintf('p%03d', $i), 10.0), range(1, 100));

    expect(0.29 * 100)->toBeLessThan(29.0);

    $paying = payingByMonth(projectRows($rows, new ProjectionAssumptions(horizonMonths: 1, churnRate: 0.29)));

    expect(count($paying['2026-11']))->toBe(71);
});

// --- Expansion -------------------------------------------------------------------------------------------------

it('compounds expansion on the remaining parties, and the aggregator reads it as expansion', function () {
    $projection = projectRows([rev('a', 100.0)], new ProjectionAssumptions(horizonMonths: 3, expansionRate: 0.1));

    $movements = movementsOf($projection);

    expect(projectedMrr($projection))->toBe([110.0, 121.0, 133.1])
        ->and(array_map(fn ($m) => $m->expansionMrr, $movements->movements()))->toBe([10.0, 11.0, 12.1])
        ->and($movements->movement('2026-11')->netRevenueRetention())->toBe(1.1);
});

it('reads negative expansion as contraction, and floors a party at zero, which the aggregator reads as churn', function () {
    $contracting = projectRows([rev('a', 100.0)], new ProjectionAssumptions(horizonMonths: 2, expansionRate: -0.5));

    expect(projectedMrr($contracting))->toBe([50.0, 25.0])
        ->and(movementsOf($contracting)->movement('2026-11')->contractionMrr)->toBe(50.0);

    $zeroed = projectRows([rev('a', 100.0, usage: 4.0)], new ProjectionAssumptions(horizonMonths: 2, expansionRate: -1.0));
    $first = movementsOf($zeroed)->movement('2026-11');

    expect(array_map(fn (PartyPeriodRevenue $r): float => $r->recurringUsd, $zeroed->projectedRows()))->toBe([0.0, 0.0])
        ->and($first->churnedMrr)->toBe(100.0)
        ->and($first->endingLogos)->toBe(0)
        ->and($first->usageUsd)->toBe(4.0);
});

// --- Growth ----------------------------------------------------------------------------------------------------

it('adds new parties at the growth rate, priced at the baseline average paying party', function () {
    $projection = projectRows(
        [rev('a', 50.0, usage: 2.0), rev('b', 100.0, usage: 4.0), rev('c', 150.0, usage: 6.0), rev('d', 100.0, usage: 8.0), rev('free', 0.0, 'free', usage: 100.0)],
        new ProjectionAssumptions(horizonMonths: 2, growthRate: 0.5),
    );

    $movements = movementsOf($projection);
    $first = $movements->movement('2026-11');
    $new = array_values(array_filter($projection->projectedRows(), fn (PartyPeriodRevenue $r): bool => str_starts_with($r->partyId, 'projected-')));

    // 4 paying × 0.5 = 2 new in month 1; 6 × 0.5 = 3 more in month 2. The free party is not counted.
    expect($first->newLogos)->toBe(2)
        ->and($first->newMrr)->toBe(200.0)
        ->and($movements->movement('2026-12')->newLogos)->toBe(3)
        ->and($movements->logos('2026-12'))->toBe(9)
        ->and($new[0]->partyId)->toBe('projected-0001')
        ->and($new[0]->recurringUsd)->toBe(100.0)
        ->and($new[0]->usageUsd)->toBe(5.0)
        ->and($new[0]->planKey)->toBeNull();
});

it('prices a new party as the assumptions say, on the plan they name', function () {
    $projection = projectRows([rev('a', 100.0), rev('b', 100.0)], new ProjectionAssumptions(
        horizonMonths: 1, growthRate: 0.5, newLogoRecurringUsd: 79.0, newLogoPlanKey: 'starter',
    ));

    $new = $projection->projectedRows()[2];

    expect($new->partyId)->toBe('projected-0001')
        ->and($new->planKey)->toBe('starter')
        ->and($new->recurringUsd)->toBe(79.0);
});

it('floors a negative growth rate at zero new parties: it never removes one, and equals no growth byte for byte', function () {
    $rows = [rev('a', 100.0), rev('b', 200.0), rev('c', 300.0)];
    $negative = projectRows($rows, new ProjectionAssumptions(horizonMonths: 6, churnRate: 0.1, growthRate: -0.5));
    $none = projectRows($rows, new ProjectionAssumptions(horizonMonths: 6, churnRate: 0.1, growthRate: 0.0));

    expect(json_encode($negative->toArray()))->toBe(json_encode($none->toArray()))
        ->and((new ProjectionAssumptions(horizonMonths: 1, growthRate: -0.5))->acquisitionRate())->toBe(0.0);

    foreach (movementsOf($negative)->movements() as $movement) {
        expect($movement->newLogos)->toBe(0)->and($movement->endingMrr)->toBeGreaterThanOrEqual(0.0);
    }

    $floored = projectRows($rows, new ProjectionAssumptions(horizonMonths: 4, expansionRate: -0.9, growthRate: -5.0));

    foreach ($floored->projectedRows() as $row) {
        expect($row->recurringUsd)->toBeGreaterThanOrEqual(0.0);
    }
});

// --- Price changes ---------------------------------------------------------------------------------------------

it('applies a price change from its month on, to its plan only, and to new parties on that plan', function () {
    $projection = projectRows([rev('a', 100.0, 'pro'), rev('b', 100.0, 'team')], new ProjectionAssumptions(
        horizonMonths: 3,
        growthRate: 0.5,
        priceChanges: [new PriceChange('2026-12', 0.1, 'pro')],
        newLogoRecurringUsd: 50.0,
        newLogoPlanKey: 'pro',
    ));

    $byParty = [];
    foreach ($projection->projectedRows() as $row) {
        $byParty[$row->partyId][$row->period] = $row->recurringUsd;
    }

    expect($byParty['a'])->toBe(['2026-11' => 100.0, '2026-12' => 110.0, '2027-01' => 110.0])
        ->and($byParty['b'])->toBe(['2026-11' => 100.0, '2026-12' => 100.0, '2027-01' => 100.0])
        ->and($byParty['projected-0001'])->toBe(['2026-11' => 50.0, '2026-12' => 55.0, '2027-01' => 55.0])
        // 2 × 0.5 = 1 new in November; 3 × 0.5 = 1.5 → 1 in December, at the changed price.
        ->and($byParty['projected-0002'])->toBe(['2026-12' => 55.0, '2027-01' => 55.0])
        ->and(movementsOf($projection)->movement('2026-12')->expansionMrr)->toBe(15.0);
});

it('applies an all-plan price cut to every party, a planless new party included', function () {
    $projection = projectRows([rev('a', 100.0, 'pro'), rev('b', 200.0, 'team')], new ProjectionAssumptions(
        horizonMonths: 2,
        growthRate: 0.5,
        priceChanges: [new PriceChange('2026-11', -0.25)],
    ));

    $first = movementsOf($projection)->movement('2026-11');

    // a 75, b 150, and one new party at the 150 average × 0.75.
    expect($first->contractionMrr)->toBe(75.0)
        ->and($first->newMrr)->toBe(112.5)
        ->and(projectedMrr($projection))->toBe([337.5, 450.0]);
});

it('reads a price change to free as churn of the plan\'s paying parties', function () {
    $first = movementsOf(projectRows([rev('a', 100.0, 'pro'), rev('b', 100.0, 'team')], new ProjectionAssumptions(
        horizonMonths: 1, priceChanges: [new PriceChange('2026-11', -1.0, 'pro')],
    )))->movement('2026-11');

    expect($first->churnedLogos)->toBe(1)->and($first->churnedMrr)->toBe(100.0)->and($first->endingMrr)->toBe(100.0);
});

it('refuses a price change at or before the anchor', function () {
    projectRows([rev('a', 100.0)], new ProjectionAssumptions(horizonMonths: 2, priceChanges: [new PriceChange(ANCHOR, 0.1)]));
})->throws(InvalidArgumentException::class, 'not after the anchor month 2026-10');

// --- Everything at once ----------------------------------------------------------------------------------------

it('keeps the aggregator\'s identity in every projected month with every assumption on', function () {
    $rows = array_map(fn (int $i): PartyPeriodRevenue => rev(sprintf('p%02d', $i), 40.0 + 15 * $i, $i % 2 ? 'pro' : 'team', usage: $i * 1.25), range(1, 12));
    $projection = projectRows($rows, new ProjectionAssumptions(
        horizonMonths: 24,
        churnRate: 0.04,
        expansionRate: 0.015,
        growthRate: 0.06,
        priceChanges: [new PriceChange('2027-01', 0.08, 'pro'), new PriceChange('2027-07', -0.05)],
    ));

    $movements = movementsOf($projection);

    expect($movements->movements())->toHaveCount(24);

    foreach ($movements->movements() as $m) {
        $rebuilt = $m->startingMrr + $m->newMrr + $m->reactivationMrr + $m->expansionMrr - $m->contractionMrr - $m->churnedMrr;

        expect(round($rebuilt, 2))->toBe($m->endingMrr)
            ->and($m->reactivationMrr)->toBe(0.0)
            ->and($m->endingLogos)->toBe($m->startingLogos + $m->newLogos - $m->churnedLogos);
    }
});

// --- Determinism -----------------------------------------------------------------------------------------------

it('gives byte-identical output for the same inputs, whatever order the baseline rows arrive in', function () {
    $rows = [rev('m', 120.0, 'team', 3.0), rev('a', 80.0, 'pro', 1.0), rev('z', 300.0, 'pro', 9.0), rev('c', 45.5, null, 0.5)];
    $assumptions = new ProjectionAssumptions(horizonMonths: 12, churnRate: 0.07, expansionRate: 0.02, growthRate: 0.11, priceChanges: [new PriceChange('2027-03', 0.05, 'pro')]);

    $first = projectRows($rows, $assumptions);
    $again = projectRows($rows, $assumptions);
    $reversed = projectRows(array_reverse($rows), $assumptions);

    expect(serialize($again))->toBe(serialize($first))
        ->and(serialize($reversed))->toBe(serialize($first))
        ->and(json_encode($reversed->toArray()))->toBe(json_encode($first->toArray()));
});

// --- The baseline ----------------------------------------------------------------------------------------------

it('sums two rows for one party, orders parties by id, and finds the paying average', function () {
    $baseline = ProjectionBaseline::fromRows(ANCHOR, [rev('b', 60.0, usage: 1.0), rev('a', 30.0), rev('b', 40.0, usage: 2.0), rev('free', 0.0, 'free')]);

    expect(array_map(fn (PartyPeriodRevenue $r): array => [$r->partyId, $r->recurringUsd, $r->usageUsd], $baseline->rows))
        ->toBe([['a', 30.0, 0.0], ['b', 100.0, 3.0], ['free', 0.0, 0.0]])
        ->and(count($baseline->paying()))->toBe(2)
        ->and($baseline->averageRecurringUsd())->toBe(65.0)
        ->and($baseline->averageUsageUsd())->toBe(1.5);
});

it('refuses a malformed baseline', function (string $anchor, array $rows, string $message) {
    expect(fn () => ProjectionBaseline::fromRows($anchor, $rows))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a malformed anchor' => ['2026-13', [], "Invalid anchor month '2026-13'"],
    'a row outside the anchor' => [ANCHOR, [rev('a', 10.0, period: '2026-09')], 'is in 2026-09, not the anchor month 2026-10'],
    'two plans for one party' => [ANCHOR, [rev('a', 10.0, 'pro'), rev('a', 10.0, 'team')], 'has two plans in 2026-10 (pro and team)'],
    'something other than a row' => [ANCHOR, [['partyId' => 'a']], 'built from PartyPeriodRevenue rows'],
]);

it('refuses a baseline party that already carries a new party\'s name', function () {
    projectRows([rev('projected-0001', 100.0)], new ProjectionAssumptions(horizonMonths: 1, growthRate: 1.0));
})->throws(InvalidArgumentException::class, 'already has a party named projected-0001');

// --- Assumptions -----------------------------------------------------------------------------------------------

it('refuses assumptions out of range', function (Closure $make, string $message) {
    expect($make)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a negative horizon' => [fn () => new ProjectionAssumptions(horizonMonths: -1), 'horizon is 0 or more'],
    'churn above 1' => [fn () => new ProjectionAssumptions(horizonMonths: 1, churnRate: 1.01), 'churn rate must be a fraction'],
    'negative churn' => [fn () => new ProjectionAssumptions(horizonMonths: 1, churnRate: -0.1), 'churn rate must be a fraction'],
    'expansion below -1' => [fn () => new ProjectionAssumptions(horizonMonths: 1, expansionRate: -1.5), 'expansion rate must be'],
    'infinite growth' => [fn () => new ProjectionAssumptions(horizonMonths: 1, growthRate: INF), 'growth rate must be finite'],
    'a negative new-logo price' => [fn () => new ProjectionAssumptions(horizonMonths: 1, newLogoRecurringUsd: -1.0), 'must be 0 or more'],
    'a price change below -1' => [fn () => new PriceChange('2026-11', -1.2), 'at least -1'],
    'a malformed price-change month' => [fn () => new PriceChange('2026-1', 0.1), "Invalid price-change month '2026-1'"],
    'a non-PriceChange' => [fn () => new ProjectionAssumptions(horizonMonths: 1, priceChanges: [0.1]), 'is not a PriceChange'],
]);

it('steps months across a year boundary', function () {
    expect(ProjectionMonth::after('2026-11', 1))->toBe('2026-12')
        ->and(ProjectionMonth::after('2026-12', 1))->toBe('2027-01')
        ->and(ProjectionMonth::after('2026-10', 27))->toBe('2029-01')
        ->and(ProjectionMonth::after('2026-10', 0))->toBe('2026-10');
});

it('would see a query if one ran, so the zeros above are not a deaf listener', function () {
    $this->expectedQueries = 1;

    DB::select('select 1');

    expect($this->queries)->toBe(1);
});
