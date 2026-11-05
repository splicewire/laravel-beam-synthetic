<?php

use Carbon\CarbonImmutable;
use Splicewire\Beam\Synthetic\Data\ScenarioEnvelopeData;
use Splicewire\Beam\Synthetic\Data\SyntheticTenantBlueprintData;
use Splicewire\Beam\Synthetic\Enums\SyntheticScenario;
use Splicewire\Beam\Synthetic\ScenarioExpander;
use Symfony\Component\Yaml\Yaml;

/** @return array<string, mixed> */
function blueprintPayload(array $overrides = []): array
{
    return array_merge([
        'id' => 'fixture-aldermoor',
        'name' => 'Aldermoor Collective',
        'ownerName' => 'Rowan Pell',
        'ownerEmail' => 'rowan.pell@aldermoor.example',
        'scaffoldPackSlug' => null,
        'planSlug' => 'professional',
        'scenario' => 'steady-ramp',
        'windowMonths' => 12,
        'targetMonthlyUsageCostUsd' => 44.0,
        'startingSeats' => 3,
        'endingSeats' => 8,
    ], $overrides);
}

it('hydrates a blueprint from the new usage-target key', function () {
    $blueprint = SyntheticTenantBlueprintData::from(blueprintPayload());

    expect($blueprint->targetMonthlyUsageCostUsd)->toBe(44.0)
        ->and($blueprint->scenario)->toBe(SyntheticScenario::SteadyRamp)
        ->and($blueprint->endedAtMonth)->toBeNull();
});

it('still reads the old targetMonthlyTokenCostUsd key, for one release', function () {
    $payload = blueprintPayload(['targetMonthlyTokenCostUsd' => 38.0]);
    unset($payload['targetMonthlyUsageCostUsd']);

    expect(SyntheticTenantBlueprintData::from($payload)->targetMonthlyUsageCostUsd)->toBe(38.0);
});

it('reads an unmigrated flagship-shaped YAML blueprint unchanged', function () {
    $yaml = <<<'YAML'
    id: fixture-hard-churn
    name: Fixture Hard Churn
    ownerName: Sam Vale
    ownerEmail: sam.vale@fixture.example
    scaffoldPackSlug: demo
    planSlug: professional
    scenario: steady-ramp
    windowMonths: 12
    endedAtMonth: 8
    targetMonthlyTokenCostUsd: 38.0
    startingSeats: 6
    endingSeats: 10
    YAML;

    $blueprint = SyntheticTenantBlueprintData::from(Yaml::parse($yaml));

    expect($blueprint->targetMonthlyUsageCostUsd)->toBe(38.0)
        ->and($blueprint->endedAtMonth)->toBe(8)
        ->and($blueprint->scaffoldPackSlug)->toBe('demo');
});

it('refuses a payload that sets both the new and the old usage-target key', function () {
    expect(fn () => SyntheticTenantBlueprintData::from(blueprintPayload(['targetMonthlyTokenCostUsd' => 1.0])))
        ->toThrow(InvalidArgumentException::class, 'targetMonthlyTokenCostUsd is its old name');
});

it('defaults the usage meter from beam.synthetic.usage_meter, and keeps an explicit one', function () {
    expect(config('beam.synthetic.usage_meter'))->toBe('openai.tokens')
        ->and(SyntheticTenantBlueprintData::from(blueprintPayload())->usageMeter)->toBe('openai.tokens');

    config()->set('beam.synthetic.usage_meter', 'compute.minutes');

    expect(SyntheticTenantBlueprintData::from(blueprintPayload())->usageMeter)->toBe('compute.minutes')
        ->and(SyntheticTenantBlueprintData::from(blueprintPayload(['usageMeter' => 'storage.gb']))->usageMeter)->toBe('storage.gb');
});

it('rejects a scenario slug outside the enum, naming it and the valid ones', function () {
    expect(fn () => SyntheticTenantBlueprintData::from(blueprintPayload(['scenario' => 'hockey-stick'])))
        ->toThrow(InvalidArgumentException::class, "unknown scenario 'hockey-stick'. Valid scenarios: steady-ramp, seasonal, churned-then-resumed.");

    expect(fn () => SyntheticTenantBlueprintData::from(array_diff_key(blueprintPayload(), ['scenario' => true])))
        ->toThrow(InvalidArgumentException::class, 'unknown scenario');
});

it('builds an envelope with no plan, and hard churn at the end of the churn month', function () {
    $blueprint = SyntheticTenantBlueprintData::from(blueprintPayload(['endedAtMonth' => 8]));
    $envelope = $blueprint->envelope(CarbonImmutable::parse('2027-01-01'));

    expect($envelope)->toBeInstanceOf(ScenarioEnvelopeData::class)
        ->and(property_exists($envelope, 'planSlug'))->toBeFalse()
        ->and($envelope->endedAt?->format('Y-m-d'))->toBe('2027-08-31')
        ->and($envelope->targetMonthlyUsageCostUsd)->toBe(44.0);
});

it('replays a hydrated blueprint through the expander into a full window', function () {
    $blueprint = SyntheticTenantBlueprintData::from(blueprintPayload());
    $months = array_map(fn (int $i): string => CarbonImmutable::parse('2027-01-01')->addMonths($i)->format('Y-m'), range(0, 11));

    $series = (new ScenarioExpander)->expand($blueprint->envelope(CarbonImmutable::parse('2027-01-01')), $blueprint->id, $months);

    expect(array_keys($series))->toBe($months);
});
