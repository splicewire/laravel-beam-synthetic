<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Models\CentralActivityLog;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;
use Splicewire\Beam\Synthetic\Store\StoredPortfolio;
use Splicewire\Beam\Synthetic\Store\StoredPortfolios;
use Splicewire\Beam\Synthetic\Tests\Support\SyntheticHost;
use Splicewire\Beam\Synthetic\Tests\Support\SyntheticSeatUser;

uses(SyntheticHost::class);

/*
 * The stored-portfolio store over the real migration stub: what is saved, what PortfolioRegistry reads beside the
 * Demo portfolio file, which ids are refused, and the central activity trail.
 */
beforeEach(function () {
    (require dirname(__DIR__, 2).'/database/migrations/create_beam_synthetic_portfolios_table.php.stub')->up();

    Schema::create('activity_log', function (Blueprint $t): void {
        $t->id();
        $t->string('log_name')->nullable();
        $t->text('description');
        $t->string('subject_type')->nullable();
        $t->string('subject_id')->nullable();
        $t->string('event')->nullable();
        $t->string('causer_type')->nullable();
        $t->string('causer_id')->nullable();
        $t->json('properties')->nullable();
        $t->json('attribute_changes')->nullable();
        $t->uuid('batch_uuid')->nullable();
        $t->timestamps();
    });
});

/** A fresh request scope: the store is scoped and memoizes its rows. */
function nextScope(): StoredPortfolios
{
    app()->forgetScopedInstances();

    return app(StoredPortfolios::class);
}

function draft(string $id = 'board-upside', array $overrides = []): ScenarioPortfolioData
{
    return ScenarioPortfolioData::from([
        'id' => $id,
        'label' => 'Board upside',
        'members' => [
            ['blueprint' => 'demo-quillmere-studio', 'count' => 5, 'startOffsetMonths' => 0],
            ['blueprint' => 'demo-orrowby-hart', 'count' => 1, 'startOffsetMonths' => 3],
        ],
        'churnRate' => 0.03,
        ...$overrides,
    ]);
}

it('saves a portfolio that PortfolioRegistry then reads after the declared ones, as stored', function () {
    $user = SyntheticSeatUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);

    app(StoredPortfolios::class)->save(draft(), $user);

    $registry = app(PortfolioRegistry::class);
    nextScope();

    expect(array_keys($registry->all()))->toBe(['demo-mixed-book', 'board-upside'])
        ->and($registry->get('board-upside')?->members[0]->count)->toBe(5)
        ->and($registry->get('board-upside')?->churnRate)->toBe(0.03)
        ->and($registry->isStored('board-upside'))->toBeTrue()
        ->and($registry->isStored('demo-mixed-book'))->toBeFalse()
        ->and(array_keys($registry->declared()))->toBe(['demo-mixed-book'])
        ->and(StoredPortfolio::query()->find('board-upside')->saved_by_id)->toBe((string) $user->getKey());
});

it('logs every save and delete to the central activity trail, with who and what', function () {
    $user = SyntheticSeatUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);
    $store = app(StoredPortfolios::class);

    $store->save(draft(), $user);
    $store->save(draft(overrides: ['label' => 'Board upside, revised']), $user);
    expect($store->delete('board-upside', $user))->toBeTrue()
        ->and($store->delete('board-upside', $user))->toBeFalse();

    $log = CentralActivityLog::query()->where('log_name', StoredPortfolios::LOG_NAME)->orderBy('id')->get();

    expect($log->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($log->pluck('subject_id')->unique()->all())->toBe(['board-upside'])
        ->and($log->pluck('causer_id')->unique()->all())->toBe([(string) $user->getKey()])
        ->and($log[1]->properties['before']['label'])->toBe('Board upside')
        ->and($log[1]->properties['after']['label'])->toBe('Board upside, revised')
        ->and($log[2]->properties['before']['label'])->toBe('Board upside, revised');
});

it('refuses a declared id, a blueprint the host lacks and an id that is not kebab-case, writing nothing', function (ScenarioPortfolioData $portfolio, string $why) {
    expect(fn () => app(StoredPortfolios::class)->save($portfolio))->toThrow(InvalidArgumentException::class, $why)
        ->and(StoredPortfolio::query()->count())->toBe(0)
        ->and(CentralActivityLog::query()->count())->toBe(0);
})->with([
    'the Demo file\'s id' => fn () => [draft('demo-mixed-book'), 'is declared by the host'],
    'an unknown blueprint' => fn () => [draft(overrides: ['members' => [['blueprint' => 'no-such-blueprint']]]), 'no-such-blueprint'],
    'a capitalised id' => fn () => [draft('Board-Upside'), 'kebab-case'],
    'an id with a colon' => fn () => [draft('scenario:x'), 'kebab-case'],
]);

it('lets a declared portfolio win over a stored row under the same id, and names the shadowed row', function () {
    // A row written before a file came to declare its id (inserted directly: save() refuses it).
    StoredPortfolio::query()->create(['id' => 'demo-mixed-book', 'label' => 'Stale copy', 'definition' => draft('demo-mixed-book')->toArray()]);

    $store = nextScope();
    $registry = app(PortfolioRegistry::class);

    expect($registry->get('demo-mixed-book')?->label)->toBe('Demo mixed book')
        ->and(array_keys($registry->all()))->toBe(['demo-mixed-book'])
        ->and($store->all())->toBe([])
        ->and($store->shadowed())->toBe(['demo-mixed-book']);
});

it('validates a row again when it is read: one that no longer validates is left out and reported', function () {
    StoredPortfolio::query()->create(['id' => 'orphaned', 'label' => 'Orphaned', 'definition' => [
        'label' => 'Orphaned', 'members' => [['blueprint' => 'a-removed-blueprint', 'count' => 1]],
    ]]);
    StoredPortfolio::query()->create(['id' => 'broken', 'label' => 'Broken', 'definition' => ['label' => 'Broken', 'members' => []]]);
    StoredPortfolio::query()->create(['id' => 'fine', 'label' => 'Fine', 'definition' => draft('fine')->toArray()]);

    $store = nextScope();

    expect(array_keys($store->all()))->toBe(['fine'])
        ->and($store->invalid())->toHaveKeys(['orphaned', 'broken'])
        ->and($store->invalid()['orphaned'])->toContain('a-removed-blueprint')
        ->and($store->invalid()['broken'])->toContain('has no members')
        ->and(app(PortfolioRegistry::class)->get('orphaned'))->toBeNull();
});

it('reads a declared id with no query, and the store at most twice per scope', function () {
    app(StoredPortfolios::class)->save(draft());
    nextScope();
    $registry = app(PortfolioRegistry::class);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $registry->get('demo-mixed-book');
    $registry->declared();
    $registry->firstId();
    expect($queries)->toBe([]);

    $registry->get('board-upside');
    $registry->get('board-upside');
    $registry->all();
    expect($queries)->toHaveCount(2);
});

it('is off, with no query at all, when the host keeps no stored portfolios', function () {
    config(['beam.synthetic.portfolio_store' => false]);
    $store = nextScope();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect(array_keys(app(PortfolioRegistry::class)->all()))->toBe(['demo-mixed-book'])
        ->and(app(PortfolioRegistry::class)->get('board-upside'))->toBeNull()
        ->and($queries)->toBe(0)
        ->and(fn () => $store->save(draft()))->toThrow(InvalidArgumentException::class, 'portfolio_store is false');
});

it('reads no stored portfolios, rather than failing, on a host that has not migrated the table', function () {
    Schema::drop('beam_synthetic_portfolios');
    $store = nextScope();

    expect($store->available())->toBeFalse()
        ->and(app(PortfolioRegistry::class)->get('board-upside'))->toBeNull()
        ->and(array_keys(app(PortfolioRegistry::class)->all()))->toBe(['demo-mixed-book'])
        ->and(fn () => $store->save(draft()))->toThrow(InvalidArgumentException::class, 'beam-synthetic-migrations');
});

it('publishes its migration stub under the package-tools tag splicewire:beam:install publishes', function () {
    $published = collect(ServiceProvider::pathsToPublish(null, 'beam-synthetic-migrations'))->keys()->map(fn (string $p): string => basename($p))->all();

    expect($published)->toContain('create_beam_synthetic_portfolios_table.php.stub');
});

it('answers an id that cannot be a registry key as no portfolio, rather than throwing', function () {
    $registry = app(PortfolioRegistry::class);

    expect($registry->get('Board-Upside'))->toBeNull()
        ->and($registry->get('scenario:x/y'))->toBeNull()
        ->and($registry->declares('Board-Upside'))->toBeFalse()
        ->and($registry->isStored('Board-Upside'))->toBeFalse();
});
