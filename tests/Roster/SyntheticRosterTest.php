<?php

use Carbon\CarbonImmutable;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;
use Splicewire\Beam\Synthetic\Roster\SyntheticRoster;

it('reads one blueprint as one tenant under its own id, over its own window', function () {
    $roster = SyntheticRoster::ofBlueprint(app(BlueprintRegistry::class)->get('demo-tessaline-works'));

    expect($roster->members)->toHaveCount(1)
        ->and($roster->members[0]->tenantId)->toBe('demo-tessaline-works')
        ->and($roster->members[0]->name)->toBe('Tessaline Works')
        ->and($roster->members[0]->portfolioId)->toBeNull()
        ->and($roster->length())->toBe(12)
        ->and($roster->planSlugs())->toBe(['starter']);
});

it('walks history back from now, closed months only, and the projection forward from the anchor', function () {
    $roster = SyntheticRoster::ofBlueprint(app(BlueprintRegistry::class)->get('demo-tessaline-works'));
    $history = $roster->historyMonths(CarbonImmutable::parse('2026-11-30 23:00:00'));
    $forward = $roster->forwardMonths(CarbonImmutable::parse('2026-11-30 23:00:00'), 3);

    expect($history[0])->toBe('2025-11')
        ->and($history[11])->toBe('2026-10')
        ->and($history)->toHaveCount(12)
        ->and($forward)->toBe(['2026-11', '2026-12', '2027-01']);
});

it('reads a portfolio as count copies of each member, offset, over the longest member\'s span', function () {
    $roster = SyntheticRoster::ofPortfolio(app(PortfolioRegistry::class)->get('demo-mixed-book'), app(BlueprintRegistry::class));
    $window = $roster->forwardMonths(CarbonImmutable::parse('2027-01-01'));

    expect($roster->portfolioId)->toBe('demo-mixed-book')
        ->and($roster->length())->toBe(17)
        ->and(array_map(fn ($m) => $m->offsetMonths, $roster->members))->toBe([0, 0, 0, 2, 2, 2, 2, 5, 5])
        ->and($roster->monthsFor($roster->members[7], $window))->toHaveCount(12)
        ->and($roster->monthsFor($roster->members[7], $window)[0])->toBe('2027-06')
        ->and($roster->planSlugs())->toBe(['professional', 'starter']);
});

it('refuses a portfolio naming blueprints the host lacks, naming all of them', function () {
    $portfolio = ScenarioPortfolioData::from([
        'id' => 'gappy',
        'label' => 'Gappy',
        'members' => [['blueprint' => 'nope-a'], ['blueprint' => 'demo-quillmere-studio'], ['blueprint' => 'nope-b']],
    ]);

    expect(fn () => SyntheticRoster::ofPortfolio($portfolio, app(BlueprintRegistry::class)))
        ->toThrow(InvalidArgumentException::class, "Portfolio 'gappy' names blueprints this host does not declare: nope-a, nope-b.");
});

it('gives a member that starts after the window no months', function () {
    $roster = SyntheticRoster::ofPortfolio(app(PortfolioRegistry::class)->get('demo-mixed-book'), app(BlueprintRegistry::class));

    expect($roster->monthsFor($roster->members[8], ['2027-01', '2027-02']))->toBe([]);
});
