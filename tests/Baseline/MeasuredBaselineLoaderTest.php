<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Commerce\Revenue\MeasuredRevenueLedger;
use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;
use Splicewire\Beam\Synthetic\Baseline\MeasuredBaselineLoader;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\Materialize\SyntheticTenantMaterializer;
use Splicewire\Beam\Synthetic\Projection\ProjectionAssumptions;
use Splicewire\Beam\Synthetic\Projection\ProjectionEngine;
use Splicewire\Beam\Synthetic\Roster\SyntheticRoster;
use Splicewire\Beam\Synthetic\Tests\Support\SyntheticHost;

/*
 * The baseline loader, over the live ledger on an in-memory sqlite host: the Demo blueprints materialized as
 * synthetic tenants on the Demo plans, then read back as the projection's anchor month.
 */

uses(SyntheticHost::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $this->seedDemoPlans();

    foreach (['demo-quillmere-studio', 'demo-tessaline-works', 'demo-orrowby-hart'] as $id) {
        app(SyntheticTenantMaterializer::class)->materialize(SyntheticRoster::ofBlueprint(app(BlueprintRegistry::class)->get($id)));
    }
});

/** @param  list<PartyPeriodRevenue>  $rows */
function baselineFacts(array $rows): array
{
    return array_map(fn (PartyPeriodRevenue $r): array => [$r->partyId, $r->period, $r->planKey, round($r->recurringUsd, 2), round($r->usageUsd, 2)], $rows);
}

it('loads the anchor month exactly as the ledger reads it', function () {
    $ledgerRows = app(MeasuredRevenueLedger::class)->read(['2026-10']);
    $baseline = app(MeasuredBaselineLoader::class)->load('2026-10');

    expect($ledgerRows)->not->toBe([])
        ->and($baseline->anchor)->toBe('2026-10')
        ->and(baselineFacts($baseline->rows))->toBe(baselineFacts($ledgerRows))
        ->and(count($baseline->paying()))->toBeGreaterThan(0);
});

it('takes the last closed month as of a moment, and narrows to the parties asked', function () {
    $loader = app(MeasuredBaselineLoader::class);

    expect($loader->lastClosed(CarbonImmutable::parse('2026-11-30 23:59:00'))->anchor)->toBe('2026-10')
        ->and(array_map(fn (PartyPeriodRevenue $r): string => $r->partyId, $loader->load('2026-10', ['demo-tessaline-works'])->rows))
        ->toBe(['demo-tessaline-works']);
});

it('refuses an open or future anchor', function (string $anchor) {
    app(MeasuredBaselineLoader::class)->load($anchor);
})->with(['the current month' => '2026-11', 'a future month' => '2027-02'])
    ->throws(InvalidArgumentException::class, 'is not before the current month 2026-11');

it('is the only part that reads: projecting the loaded baseline makes zero queries', function () {
    $baseline = app(MeasuredBaselineLoader::class)->load('2026-10');

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $projection = (new ProjectionEngine)->project($baseline, new ProjectionAssumptions(horizonMonths: 12, churnRate: 0.02, expansionRate: 0.01, growthRate: 0.05));

    expect($projection->projectedPeriods())->toHaveCount(12)
        ->and($queries)->toBe(0);

    DB::select('select 1');

    expect($queries)->toBe(1);
});
