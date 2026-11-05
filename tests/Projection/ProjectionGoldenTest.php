<?php

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;
use Splicewire\Beam\Commerce\Revenue\RevenueMovements;
use Splicewire\Beam\Synthetic\Projection\PriceChange;
use Splicewire\Beam\Synthetic\Projection\ProjectionAssumptions;
use Splicewire\Beam\Synthetic\Projection\ProjectionBaseline;
use Splicewire\Beam\Synthetic\Projection\ProjectionEngine;
use Splicewire\Beam\Synthetic\Projection\RevenueProjection;

/*
 * One projection, pinned. Every assumption is on: churn, expansion, growth, a plan-scoped price change and a priced
 * new party, over a four-party book on the Demo plans' prices. The rows below were worked by hand month by month
 * (see each month's comment) before they were pinned.
 *
 * This test never writes. If the engine's arithmetic changes on purpose, rework the months by hand and edit the
 * literal and the hash in the same commit.
 */

const GOLDEN_SHA256 = 'de565344e6ace42383a03aff36d48162175f857379c0e0c147983b45053475b5';

function goldenProjection(): RevenueProjection
{
    $baseline = ProjectionBaseline::fromRows('2026-10', [
        new PartyPeriodRevenue('acme', '2026-10', 'professional', 379.0, 42.5),
        new PartyPeriodRevenue('birch', '2026-10', 'starter', 99.0, 6.25),
        new PartyPeriodRevenue('cobalt', '2026-10', 'professional', 299.0, 18.0),
        new PartyPeriodRevenue('dune', '2026-10', 'starter', 99.0, 0.0),
    ]);

    return (new ProjectionEngine)->project($baseline, new ProjectionAssumptions(
        horizonMonths: 4,
        churnRate: 0.25,
        expansionRate: 0.02,
        growthRate: 0.25,
        priceChanges: [new PriceChange('2027-01', 0.10, 'starter')],
        newLogoRecurringUsd: 99.0,
        newLogoPlanKey: 'starter',
    ));
}

it('projects the pinned rows', function () {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $rows = array_map(fn (array $r): array => array_values($r), goldenProjection()->toArray()['rows']);

    expect($rows)->toBe([
        // The anchor, as given.
        ['acme', '2026-10', 'professional', 379.0, 42.5],
        ['birch', '2026-10', 'starter', 99.0, 6.25],
        ['cobalt', '2026-10', 'professional', 299.0, 18.0],
        ['dune', '2026-10', 'starter', 99.0, 0.0],
        // November: 4 × 0.25 = 1 leaves (acme, first by id); the rest grow 2%; 4 × 0.25 = 1 arrives at 99, with the
        // average usage (42.5 + 6.25 + 18 + 0) / 4 = 16.6875 → 16.69.
        ['birch', '2026-11', 'starter', 100.98, 6.25],
        ['cobalt', '2026-11', 'professional', 304.98, 18.0],
        ['dune', '2026-11', 'starter', 100.98, 0.0],
        ['projected-0001', '2026-11', 'starter', 99.0, 16.69],
        // December: birch leaves; 2% again; one more arrives.
        ['cobalt', '2026-12', 'professional', 311.08, 18.0],
        ['dune', '2026-12', 'starter', 103.0, 0.0],
        ['projected-0001', '2026-12', 'starter', 100.98, 16.69],
        ['projected-0002', '2026-12', 'starter', 99.0, 16.69],
        // January: cobalt leaves; 2%, then starter +10% (103 × 1.02 × 1.1 = 115.566); the new party pays 99 × 1.1.
        ['dune', '2027-01', 'starter', 115.57, 0.0],
        ['projected-0001', '2027-01', 'starter', 113.3, 16.69],
        ['projected-0002', '2027-01', 'starter', 111.08, 16.69],
        ['projected-0003', '2027-01', 'starter', 108.9, 16.69],
        // February: dune leaves; 2%; one more at the changed price.
        ['projected-0001', '2027-02', 'starter', 115.57, 16.69],
        ['projected-0002', '2027-02', 'starter', 113.3, 16.69],
        ['projected-0003', '2027-02', 'starter', 111.08, 16.69],
        ['projected-0004', '2027-02', 'starter', 108.9, 16.69],
    ])->and($queries)->toBe(0);
});

it('aggregates through RevenueMovements unchanged', function () {
    $projection = goldenProjection();
    $movements = RevenueMovements::from($projection->periods(), $projection->rows());

    expect(array_map(fn (string $p): array => [$p, $movements->mrr($p), $movements->logos($p)], $projection->periods()))->toBe([
        ['2026-10', 876.0, 4],
        ['2026-11', 605.94, 4],
        ['2026-12', 614.06, 4],
        ['2027-01', 448.85, 4],
        ['2027-02', 448.85, 4],
    ]);

    $november = $movements->movement('2026-11');

    expect([$november->churnedMrr, $november->expansionMrr, $november->newMrr, $november->churnedLogos, $november->newLogos])
        ->toBe([379.0, 9.94, 99.0, 1, 1]);
});

it('is byte-identical to the pinned hash', function () {
    expect(hash('sha256', (string) json_encode(goldenProjection()->toArray())))->toBe(GOLDEN_SHA256);
});
