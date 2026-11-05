<?php

/*
 * Moved from the flagship's ScenarioExpanderTest with the lift: determinism (asserted per scenario),
 * structure, the seed negative control, hard churn, and the every-slug guard. Only the vocabulary
 * changed: the envelope carries no plan, and the usage target is `targetMonthlyUsageCostUsd`.
 */

use Carbon\CarbonImmutable;
use Splicewire\Beam\Synthetic\Data\ScenarioEnvelopeData;
use Splicewire\Beam\Synthetic\Data\ScenarioMonthData;
use Splicewire\Beam\Synthetic\Enums\SyntheticScenario;
use Splicewire\Beam\Synthetic\ScenarioExpander;

function steadyRampEnvelope(): ScenarioEnvelopeData
{
    return new ScenarioEnvelopeData(
        startedAt: CarbonImmutable::parse('2025-01-01'),
        endedAt: null,
        scenario: SyntheticScenario::SteadyRamp,
        targetMonthlyUsageCostUsd: 40.0,
        startingSeats: 3,
        endingSeats: 9,
    );
}

/** @return list<string> */
function twelveMonths(): array
{
    return array_map(fn (int $i): string => CarbonImmutable::parse('2025-01-01')->addMonths($i)->format('Y-m'), range(0, 11));
}

it('expands steady-ramp into a deterministic, monotonically rising monthly usage-cost series', function () {
    $expander = new ScenarioExpander;
    $months = twelveMonths();

    $first = $expander->expand(steadyRampEnvelope(), seed: 'tenant-abc', months: $months);
    $second = $expander->expand(steadyRampEnvelope(), seed: 'tenant-abc', months: $months);

    // Deterministic: same envelope + same seed => identical output.
    expect($first)->toEqual($second);

    // One ScenarioMonthData per month, keyed by YYYY-MM in order.
    expect(array_keys($first))->toBe($months);
    expect(array_values($first))->each->toBeInstanceOf(ScenarioMonthData::class);

    // Token cost rises monotonically across the window and reaches the target.
    $costs = array_map(fn (ScenarioMonthData $m) => $m->usageCostUsd, array_values($first));
    $nonDecreasing = $costs;
    sort($nonDecreasing);
    expect($costs)->toBe($nonDecreasing);
    expect($costs[0])->toBeLessThan($costs[count($costs) - 1]);
    expect(round($costs[count($costs) - 1], 2))->toBe(40.0);
});

it('ramps seats non-decreasingly from starting to ending', function () {
    $expander = new ScenarioExpander;
    $months = twelveMonths();

    $series = $expander->expand(steadyRampEnvelope(), seed: 'tenant-abc', months: $months);
    $seats = array_map(fn (ScenarioMonthData $m) => $m->seatCount, array_values($series));

    // Non-decreasing, anchored at the envelope's starting and ending seat counts.
    $nonDecreasing = $seats;
    sort($nonDecreasing);
    expect($seats)->toBe($nonDecreasing);
    expect($seats[0])->toBe(3);
    expect($seats[count($seats) - 1])->toBe(9);

    // The window ends above a 5-seat allowance, so a plan with one would bill an overage.
    expect($seats[count($seats) - 1])->toBeGreaterThan(5);
});

it('produces a different curve for a different tenant seed', function () {
    $expander = new ScenarioExpander;
    $months = twelveMonths();

    $a = $expander->expand(steadyRampEnvelope(), seed: 'tenant-abc', months: $months);
    $b = $expander->expand(steadyRampEnvelope(), seed: 'tenant-xyz', months: $months);

    expect($a)->not->toEqual($b);
});

it('expands seasonal into a deterministic, non-monotonic usage-cost curve with steady headcount', function () {
    $expander = new ScenarioExpander;
    $months = twelveMonths();

    $envelope = new ScenarioEnvelopeData(
        startedAt: CarbonImmutable::parse('2025-01-01'),
        endedAt: null,
        scenario: SyntheticScenario::Seasonal,
        targetMonthlyUsageCostUsd: 60.0,
        startingSeats: 4,
        endingSeats: 7,
    );

    $first = $expander->expand($envelope, seed: 'tenant-food', months: $months);
    $second = $expander->expand($envelope, seed: 'tenant-food', months: $months);
    expect($first)->toEqual($second);

    $costs = array_map(fn (ScenarioMonthData $m) => $m->usageCostUsd, array_values($first));

    // All positive, never above the peak target.
    expect(min($costs))->toBeGreaterThan(0.0);
    expect(max($costs))->toBeLessThanOrEqual(60.0);

    // Seasonal: the 12-month window has both a rise and a fall (not monotonic).
    $hasRise = false;
    $hasFall = false;
    for ($i = 1; $i < count($costs); $i++) {
        $hasRise = $hasRise || $costs[$i] > $costs[$i - 1];
        $hasFall = $hasFall || $costs[$i] < $costs[$i - 1];
    }
    expect($hasRise)->toBeTrue();
    expect($hasFall)->toBeTrue();

    // Headcount still grows steadily (only usage is seasonal).
    $seats = array_map(fn (ScenarioMonthData $m) => $m->seatCount, array_values($first));
    $nonDecreasing = $seats;
    sort($nonDecreasing);
    expect($seats)->toBe($nonDecreasing);
    expect($seats[0])->toBe(4);
    expect($seats[count($seats) - 1])->toBe(7);
});

it('expands churned-then-resumed into an active → dormant → recovered curve with retained seats', function () {
    $expander = new ScenarioExpander;
    $months = twelveMonths();

    $envelope = new ScenarioEnvelopeData(
        startedAt: CarbonImmutable::parse('2025-01-01'),
        endedAt: null,
        scenario: SyntheticScenario::ChurnedThenResumed,
        targetMonthlyUsageCostUsd: 50.0,
        startingSeats: 5,
        endingSeats: 8,
    );

    $first = $expander->expand($envelope, seed: 'tenant-churn', months: $months);
    expect($first)->toEqual($expander->expand($envelope, seed: 'tenant-churn', months: $months));

    $costs = array_map(fn (ScenarioMonthData $m) => $m->usageCostUsd, array_values($first));
    $n = count($costs);

    // A deep dormant trough in the middle, with active usage before and after.
    $minValue = min($costs);
    $minIndex = array_search($minValue, $costs, true);
    expect($minValue)->toBeGreaterThan(0.0)
        ->and($minValue)->toBeLessThan(0.15 * 50.0);          // genuinely dormant
    expect($minIndex)->toBeGreaterThan(0)->toBeLessThan($n - 1); // in the interior
    expect($costs[0])->toBeGreaterThan(0.3 * 50.0);            // active at the start
    expect($costs[$n - 1])->toBeGreaterThan(0.3 * 50.0);       // recovered by the end

    // Seats are retained through the dormancy (only usage churns).
    $seats = array_map(fn (ScenarioMonthData $m) => $m->seatCount, array_values($first));
    $nonDecreasing = $seats;
    sort($nonDecreasing);
    expect($seats)->toBe($nonDecreasing);
});

it('zeroes usage and seats after a hard-churn month, orthogonally to the scenario', function () {
    $expander = new ScenarioExpander;
    $months = twelveMonths(); // 2025-01 .. 2025-12

    // A steady-ramp customer that actually left at the end of month 8 (2025-08):
    // churn is a window property layered on top of any scenario's curve.
    $envelope = new ScenarioEnvelopeData(
        startedAt: CarbonImmutable::parse('2025-01-01'),
        endedAt: CarbonImmutable::parse('2025-08-31'),
        scenario: SyntheticScenario::SteadyRamp,
        targetMonthlyUsageCostUsd: 40.0,
        startingSeats: 3,
        endingSeats: 9,
    );

    $series = $expander->expand($envelope, seed: 'tenant-gone', months: $months);

    // The churn month itself stays active (they were billed for part of it).
    expect($series['2025-08']->usageCostUsd)->toBeGreaterThan(0.0)
        ->and($series['2025-08']->seatCount)->toBeGreaterThan(0);

    // Every month strictly after churn is dead: no usage, no seats.
    foreach (['2025-09', '2025-10', '2025-11', '2025-12'] as $dead) {
        expect($series[$dead]->usageCostUsd)->toBe(0.0)
            ->and($series[$dead]->seatCount)->toBe(0);
    }
});

it('can expand every declared scenario slug (enum stays the canonical source)', function (SyntheticScenario $scenario) {
    $expander = new ScenarioExpander;
    $months = twelveMonths();

    $envelope = new ScenarioEnvelopeData(
        startedAt: CarbonImmutable::parse('2025-01-01'),
        endedAt: null,
        scenario: $scenario,
        targetMonthlyUsageCostUsd: 40.0,
        startingSeats: 3,
        endingSeats: 9,
    );

    // Every enum case must resolve to expander behavior — an unhandled case
    // throws \UnhandledMatchError, failing this guard the moment a slug is added
    // without a matching branch.
    $series = $expander->expand($envelope, seed: 'tenant-abc', months: $months);

    expect(array_keys($series))->toBe($months);
})->with(SyntheticScenario::cases());
