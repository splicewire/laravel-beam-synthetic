<?php

namespace Splicewire\Beam\Synthetic\Golden;

use Carbon\CarbonImmutable;
use Splicewire\Beam\Synthetic\Data\ScenarioEnvelopeData;
use Splicewire\Beam\Synthetic\Data\ScenarioMonthData;
use Splicewire\Beam\Synthetic\Enums\SyntheticScenario;
use Splicewire\Beam\Synthetic\ScenarioExpander;

/**
 * The fixed envelopes the committed golden curves are taken from, named once so the command that
 * writes the fixture and the test that asserts against it cannot describe different inputs.
 *
 * ## Why the cases are what they are
 *
 * One per {@see SyntheticScenario} case, plus two more:
 *
 * - **hard churn**, because the cutoff does not decorate a curve, it destroys its tail, so a churned
 *   fixture exercises less of the curve than the others, not more;
 * - **a single month**, the only case that reaches the kernel's `n <= 1` branch of its rising
 *   fractions.
 *
 * ## The anchor is fixed, deliberately
 *
 * A replayed window is anchored on now, and the seasonal shape reads the month of the year off each
 * key, so seeded history moves every month with no code change. A golden fixture cannot pin that and
 * does not try to. It pins the kernel at a fixed anchor: given this envelope, this seed and these
 * months, exactly these numbers.
 *
 * ## The fixture's keys are its frozen format
 *
 * The fixture was captured before the usage target was renamed, and its rows still spell the scalar
 * `token_cost_usd`. The keys are kept, so the fixture's bytes are unchanged and the rename is proven to
 * have moved no number. `usageCostUsd` is written under `token_cost_usd`.
 */
class GoldenCurveCases
{
    /** @return array<string, array{envelope: ScenarioEnvelopeData, seed: string, months: list<string>}> */
    public static function all(): array
    {
        $anchor = CarbonImmutable::create(2027, 1, 1)->startOfMonth();
        $year = array_map(fn (int $i): string => $anchor->addMonths($i)->format('Y-m'), range(0, 11));

        return [
            'steady-ramp' => self::case($anchor, SyntheticScenario::SteadyRamp, 38.0, 6, 10, $year, 'golden-steady-ramp'),
            'seasonal' => self::case($anchor, SyntheticScenario::Seasonal, 58.0, 4, 8, $year, 'golden-seasonal'),
            'churned-then-resumed' => self::case($anchor, SyntheticScenario::ChurnedThenResumed, 50.0, 5, 8, $year, 'golden-churned'),
            'hard-churn' => self::case($anchor, SyntheticScenario::SteadyRamp, 38.0, 6, 10, $year, 'golden-hard-churn', $anchor->addMonths(7)->endOfMonth()),
            'single-month' => self::case($anchor, SyntheticScenario::SteadyRamp, 42.0, 3, 9, [$anchor->format('Y-m')], 'golden-single-month'),
        ];
    }

    /** The committed fixture's path. */
    public static function path(): string
    {
        return dirname(__DIR__, 2).'/tests/fixtures/synthetic/scenario-curves.json';
    }

    /**
     * Expand every case into the fixture's on-disk shape.
     *
     * @return array<string, list<array{month: string, token_cost_usd: float, seats: int}>>
     */
    public static function expandAll(ScenarioExpander $expander): array
    {
        $out = [];

        foreach (self::all() as $name => $case) {
            $out[$name] = array_map(fn (ScenarioMonthData $m): array => [
                'month' => $m->month,
                'token_cost_usd' => $m->usageCostUsd,
                'seats' => $m->seatCount,
            ], array_values($expander->expand($case['envelope'], $case['seed'], $case['months'])));
        }

        return self::normalise($out);
    }

    /**
     * Normalise a curve set to the fixture's exact scalar types.
     *
     * ⚠️ Applied to **both** sides of a comparison, because the two sides arrive with different types
     * for the same value: `json_encode(38.0)` emits `38`, `json_decode` returns `int(38)`, and the
     * expander returns `float(38.0)`. A strict comparison of the raw forms fails on every whole-dollar
     * month for a reason that has nothing to do with the curve. Normalising in one shared place is what
     * makes it impossible to guard one side and forget the other.
     *
     * @param  array<string, list<array{month: string, token_cost_usd: mixed, seats: mixed}>>  $curves
     * @return array<string, list<array{month: string, token_cost_usd: float, seats: int}>>
     */
    public static function normalise(array $curves): array
    {
        return array_map(fn (array $rows): array => array_map(fn (array $r): array => [
            'month' => (string) $r['month'],
            'token_cost_usd' => (float) $r['token_cost_usd'],
            'seats' => (int) $r['seats'],
        ], $rows), $curves);
    }

    /** @param list<string> $months */
    private static function case(
        CarbonImmutable $anchor,
        SyntheticScenario $scenario,
        float $target,
        int $from,
        int $to,
        array $months,
        string $seed,
        ?CarbonImmutable $endedAt = null,
    ): array {
        return [
            'envelope' => new ScenarioEnvelopeData(
                startedAt: $anchor,
                endedAt: $endedAt,
                scenario: $scenario,
                targetMonthlyUsageCostUsd: $target,
                startingSeats: $from,
                endingSeats: $to,
            ),
            'seed' => $seed,
            'months' => $months,
        ];
    }
}
