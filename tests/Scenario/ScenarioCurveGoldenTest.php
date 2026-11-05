<?php

use Splicewire\Beam\Synthetic\Enums\SyntheticScenario;
use Splicewire\Beam\Synthetic\Golden\GoldenCurveCases;
use Splicewire\Beam\Synthetic\ScenarioExpander;

/**
 * A golden fixture for the curve arithmetic: the one property {@see ScenarioExpanderTest} cannot hold.
 *
 * That file proves the expander is a function (determinism, structure, a seed negative control). It
 * cannot prove it is the right function: perturbing a coefficient (the ramp's start fraction, the
 * seasonal amplitude or jitter, the dip window, the recovery ramp, the rising-fraction epsilon) leaves
 * it green while every value changes.
 *
 * The fixture's bytes are the ones captured in the flagship before the kernel was lifted and its
 * vocabulary neutralised, copied in unchanged (sha256 `3a383f4e…5741`). Passing against them proves the
 * lift and the rename moved no number.
 *
 * ## ⚠️ This test has no write mode, deliberately
 *
 * Regeneration is a command, verification is a test, and the test never writes. A test that can
 * rewrite its own fixture can be made incapable of failing.
 */
const GOLDEN_FIXTURE_SHA256 = '3a383f4e14607503f41cb8451eaf3df672bf6f13b11a4823c1c6f3f39ad75741';

it('reads the fixture exactly as it was captured before the lift', function () {
    expect(hash_file('sha256', GoldenCurveCases::path()))->toBe(GOLDEN_FIXTURE_SHA256);
});

it('matches the committed golden curves', function () {
    expect(file_exists(GoldenCurveCases::path()))->toBeTrue('Golden fixture missing.');

    $expected = json_decode((string) file_get_contents(GoldenCurveCases::path()), true);

    expect($expected)->toBeArray()->not->toBeEmpty(
        'Golden fixture is empty or unparseable; an empty fixture would make every comparison below vacuous.',
    );

    // Both sides through the same normaliser: see GoldenCurveCases::normalise().
    $actual = GoldenCurveCases::expandAll(new ScenarioExpander);

    expect(array_keys($actual))->toBe(['steady-ramp', 'seasonal', 'churned-then-resumed', 'hard-churn', 'single-month'])
        ->and($actual)->toBe(GoldenCurveCases::normalise($expected));
});

it('covers every declared scenario, so a new case cannot land without a fixture', function () {
    $covered = array_keys(GoldenCurveCases::all());

    foreach (SyntheticScenario::cases() as $case) {
        expect($covered)->toContain($case->value);
    }
});

it('pins the hard-churn tail to zero rather than merely to something', function () {
    $rows = GoldenCurveCases::expandAll(new ScenarioExpander)['hard-churn'];
    $tail = array_slice($rows, 8);

    expect($tail)->not->toBeEmpty();

    foreach ($tail as $row) {
        expect($row['token_cost_usd'])->toBe(0.0)
            ->and($row['seats'])->toBe(0);
    }
});
