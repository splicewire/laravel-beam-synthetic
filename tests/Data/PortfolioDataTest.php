<?php

use Splicewire\Beam\Synthetic\Data\PortfolioMemberData;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;

/** @return array<string, mixed> */
function portfolioPayload(array $overrides = []): array
{
    return array_merge([
        'id' => 'fixture-book',
        'label' => 'Fixture book',
        'members' => [
            ['blueprint' => 'a', 'count' => 2, 'startOffsetMonths' => 0],
            ['blueprint' => 'b'],
            ['blueprint' => 'a', 'count' => 1, 'startOffsetMonths' => 6],
        ],
    ], $overrides);
}

it('hydrates members as data objects with their defaults', function () {
    $portfolio = ScenarioPortfolioData::from(portfolioPayload());

    expect($portfolio->members)->toHaveCount(3)
        ->and($portfolio->members)->each->toBeInstanceOf(PortfolioMemberData::class)
        ->and($portfolio->members[1]->count)->toBe(1)
        ->and($portfolio->members[1]->startOffsetMonths)->toBe(0)
        ->and($portfolio->churnRate)->toBeNull()
        ->and($portfolio->expansionRate)->toBeNull()
        ->and($portfolio->blueprintIds())->toBe(['a', 'b']);
});

it('reads optional churn and expansion rates', function () {
    $portfolio = ScenarioPortfolioData::from(portfolioPayload(['churnRate' => 0.02, 'expansionRate' => 0]));

    expect($portfolio->churnRate)->toBe(0.02)->and($portfolio->expansionRate)->toBe(0.0);
});

it('refuses a portfolio that could not be read as a population', function (array $overrides, string $message) {
    expect(fn () => ScenarioPortfolioData::from(portfolioPayload($overrides)))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no members' => [['members' => []], 'has no members'],
    'a nameless member' => [['members' => [['count' => 1]]], 'names no blueprint'],
    'a zero count' => [['members' => [['blueprint' => 'a', 'count' => 0]]], 'count below 1'],
    'a negative offset' => [['members' => [['blueprint' => 'a', 'startOffsetMonths' => -1]]], 'negative start offset'],
    'a churn rate above 1' => [['churnRate' => 1.5], 'churnRate must be a fraction'],
    'a negative expansion rate' => [['expansionRate' => -0.1], 'expansionRate must be a fraction'],
]);
