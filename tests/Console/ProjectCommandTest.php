<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\Roster\SyntheticRoster;
use Splicewire\Beam\Synthetic\ScenarioExpander;

/**
 * The projection: run a blueprint or a portfolio forward, read nothing, write nothing.
 *
 * This file's harness has no migrated database at all, so a command that touched one would fail here rather than
 * passing against a schema; the zero-query test then rules out a read that happened to succeed.
 */
function projection(array $arguments): array
{
    Artisan::call('synthetic:project', [...$arguments, '--json' => true]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('lists the blueprints and portfolios when given neither', function () {
    $this->artisan('synthetic:project')
        ->assertSuccessful()
        ->expectsOutputToContain('Blueprints:')
        ->expectsOutputToContain('demo-quillmere-studio')
        ->expectsOutputToContain('Portfolios:')
        ->expectsOutputToContain('demo-mixed-book');
});

it('says so when the host declares no blueprints, which reads differently from a missing one', function () {
    config(['beam.synthetic.blueprint_paths' => [], 'beam.synthetic.portfolio_paths' => []]);

    $this->artisan('synthetic:project')
        ->assertSuccessful()
        ->expectsOutputToContain('This host declares no synthetic blueprints');
});

it('refuses a blueprint or portfolio id the host does not declare, naming it', function () {
    $this->artisan('synthetic:project', ['blueprint' => 'no-such-tenant'])
        ->assertFailed()
        ->expectsOutputToContain("No blueprint with id 'no-such-tenant'");

    $this->artisan('synthetic:project', ['--portfolio' => 'no-such-book'])
        ->assertFailed()
        ->expectsOutputToContain("No portfolio with id 'no-such-book'");
});

it('refuses an anchor that is not a month', function () {
    $this->artisan('synthetic:project', ['blueprint' => 'demo-quillmere-studio', '--from' => '2027-6-1'])
        ->assertFailed()
        ->expectsOutputToContain('--from must be a month, YYYY-MM');
});

it('runs no query of any kind, for a blueprint or a portfolio', function () {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->artisan('synthetic:project', ['blueprint' => 'demo-quillmere-studio', '--months' => 6, '--from' => '2026-10', '--json' => true])->assertSuccessful();
    $this->artisan('synthetic:project', ['--portfolio' => 'demo-mixed-book', '--from' => '2026-10', '--json' => true])->assertSuccessful();
    $this->artisan('synthetic:project', ['--portfolio' => 'demo-mixed-book'])->assertSuccessful();

    // Not "no writes": no queries at all. A projection that reads the database is one refactor away from writing to it.
    expect($queries)->toBe(0);
});

it('would see a query if one ran, so the zero above is not a deaf listener', function () {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    DB::select('select 1');

    expect($queries)->toBe(1);
});

it('replays byte-identically for the same blueprint, anchor and window', function () {
    $run = function (): string {
        Artisan::call('synthetic:project', ['blueprint' => 'demo-tessaline-works', '--months' => 12, '--from' => '2027-01', '--json' => true]);

        return Artisan::output();
    };

    expect($run())->toBe($run());
});

it('projects FORWARD from the anchor, unlike the materializer, which walks back', function () {
    // A partial date must not inherit day 31 and overflow June before startOfMonth().
    $payload = CarbonImmutable::withTestNow('2026-10-31 12:00:00', fn () => projection([
        'blueprint' => 'demo-quillmere-studio', '--months' => 3, '--from' => '2027-06',
    ]));

    expect(array_column($payload['tenants'][0]['projection'], 'month'))->toBe(['2027-06', '2027-07', '2027-08'])
        ->and($payload['blueprint'])->toBe('demo-quillmere-studio')
        ->and($payload['portfolio'])->toBeNull()
        ->and($payload['readOnly'])->toBeTrue();
});

it('defaults to the blueprint window from this month', function () {
    $payload = CarbonImmutable::withTestNow('2026-11-15', fn () => projection(['blueprint' => 'demo-orrowby-hart']));

    expect($payload['from'])->toBe('2026-11')
        ->and($payload['months'])->toBe(12)
        ->and($payload['tenants'][0])->toMatchArray(['tenant' => 'demo-orrowby-hart', 'scenario' => 'churned-then-resumed', 'plan' => 'professional', 'usageMeter' => 'openai.tokens']);
});

it('projects a portfolio as its members, each from its offset, with totals that sum them', function () {
    $payload = projection(['--portfolio' => 'demo-mixed-book', '--from' => '2027-01']);

    expect($payload['portfolio'])->toBe('demo-mixed-book')
        ->and($payload['months'])->toBe(17)
        ->and(array_column($payload['tenants'], 'tenant'))->toHaveCount(9)
        ->and($payload['tenants'][0]['startsAt'])->toBe('2027-01')
        ->and($payload['tenants'][3]['startsAt'])->toBe('2027-03')
        ->and($payload['tenants'][8]['startsAt'])->toBe('2027-06');

    // Each month's total is the sum of the members present that month.
    foreach ($payload['totals'] as $total) {
        $usage = 0.0;
        $seats = 0;
        foreach ($payload['tenants'] as $tenant) {
            foreach ($tenant['projection'] as $month) {
                if ($month['month'] === $total['month']) {
                    $usage += $month['usageCostUsd'];
                    $seats += $month['seatCount'];
                }
            }
        }

        expect($total['usageCostUsd'])->toEqualWithDelta(round($usage, 2), 0.000001)
            ->and($total['seatCount'])->toBe($seats);
    }

    // Before the second cohort joins, only the first three tenants count.
    expect($payload['totals'][0]['seatCount'])->toBe(array_sum(array_map(fn ($t) => $t['projection'][0]['seatCount'], array_slice($payload['tenants'], 0, 3))));
});

it('projects the same curve the materializer writes for the same months', function () {
    // The materializer's history for a 2027-01 start is these months; the projection from 2027-01 must agree on
    // every number, because both read the same roster member and seed.
    $payload = projection(['blueprint' => 'demo-quillmere-studio', '--from' => '2027-01']);
    $roster = SyntheticRoster::ofBlueprint(app(BlueprintRegistry::class)->get('demo-quillmere-studio'));
    $history = $roster->historyMonths(CarbonImmutable::parse('2028-01-10'));
    $series = $roster->series(app(ScenarioExpander::class), $roster->members[0], $history);

    expect($history[0])->toBe('2027-01')
        ->and($payload['tenants'][0]['projection'])->toBe(array_map(fn ($m) => $m->toArray(), array_values($series)));
});
