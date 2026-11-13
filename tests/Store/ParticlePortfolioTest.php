<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;
use Splicewire\Beam\Synthetic\Data\StoredPortfolioData;
use Splicewire\Beam\Synthetic\Store\StoredPortfolios;
use Splicewire\Beam\Synthetic\Tests\Support\SyntheticHost;
use Splicewire\Beam\Synthetic\Tests\Support\SyntheticSeatUser;
use Splicewire\Beam\Write\Contracts\WriteGate;
use Splicewire\Beam\Write\WriteNotAuthorized;

uses(SyntheticHost::class);

beforeEach(function () {
    Schema::create('beam_particles', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('schema_ref')->nullable();
        $table->string('schema_id')->nullable();
        $table->string('migration_status')->nullable();
        $table->string('head_version')->nullable();
        $table->string('source_tier')->default('local');
        $table->json('payload');
        $table->json('meta')->nullable();
        $table->timestamps();
    });
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
    Gate::define(StoredPortfolioData::WRITE_ABILITY, fn ($actor) => $actor->email === 'ada@example.test');
});

it('replaces and deletes by scenario key while keeping a distinct particle id without a portfolio table', function () {
    $actor = SyntheticSeatUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);
    $this->actingAs($actor);
    $portfolio = ScenarioPortfolioData::from([
        'id' => 'board-upside', 'label' => 'Upside',
        'members' => [['blueprint' => 'demo-quillmere-studio', 'count' => 2]],
    ]);
    expect(Schema::hasTable('beam_synthetic_portfolios'))->toBeFalse();
    $store = app(StoredPortfolios::class);
    $store->save($portfolio, $actor);
    $particle = DB::table('beam_particles')->sole();
    expect($particle->id)->not->toBe('board-upside');
    $store->save(ScenarioPortfolioData::from([...$portfolio->toArray(), 'label' => 'Revised']), $actor);
    app()->forgetScopedInstances();
    expect(app(PortfolioRegistry::class)->get('board-upside')?->label)->toBe('Revised')
        ->and(DB::table('beam_particles')->sole()->id)->toBe($particle->id)
        ->and(app(StoredPortfolios::class)->delete('board-upside', $actor))->toBeTrue()
        ->and(app(StoredPortfolios::class)->delete('board-upside', $actor))->toBeFalse()
        ->and(DB::table('beam_particles')->count())->toBe(0);
});

it('preserves trusted viewer and CLI reads while refusing unauthorized central mutations', function () {
    $manager = SyntheticSeatUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);
    $viewer = SyntheticSeatUser::create(['name' => 'Viewer', 'email' => 'viewer@example.test', 'password' => 'x']);
    $portfolio = ScenarioPortfolioData::from(['id' => 'shared-board', 'label' => 'Shared', 'members' => [['blueprint' => 'demo-quillmere-studio']]]);
    app(StoredPortfolios::class)->save($portfolio, $manager);
    $this->actingAs($viewer);
    expect(app(PortfolioRegistry::class)->get('shared-board')?->label)->toBe('Shared')
        ->and(fn () => app(StoredPortfolios::class)->save($portfolio, $viewer))->toThrow(AuthorizationException::class)
        ->and(fn () => app(StoredPortfolios::class)->delete('shared-board', $viewer))->toThrow(AuthorizationException::class);
    auth()->logout();
    app()->forgetScopedInstances();
    expect(app(PortfolioRegistry::class)->get('shared-board')?->id)->toBe('shared-board')
        ->and(fn () => app(StoredPortfolios::class)->save($portfolio))->toThrow(AuthorizationException::class);
});

it('uses central storage while a different tenant database is active and isolates other schemas and owners', function () {
    config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    app()->instance(TenancyContext::class, new class implements TenancyContext
    {
        public function isMultiTenant(): bool
        {
            return true;
        }

        public function isActive(): bool
        {
            return true;
        }

        public function currentTenant(): ?object
        {
            return (object) ['id' => 'a'];
        }

        public function currentTenantId(): int|string|null
        {
            return 'a';
        }

        public function centralConnection(): ?string
        {
            return 'central';
        }
    });
    $actor = SyntheticSeatUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);
    config(['database.default' => 'tenant']);
    $portfolio = ScenarioPortfolioData::from(['id' => 'central-board', 'label' => 'Central', 'members' => [['blueprint' => 'demo-quillmere-studio']]]);
    app(StoredPortfolios::class)->save($portfolio, $actor);
    $record = BeamParticle::on('central')->sole();
    expect($record->meta['owner'])->toBe(['type' => 'central', 'id' => 'host'])
        ->and(Schema::connection('tenant')->hasTable('beam_particles'))->toBeFalse();
    foreach ([['schema_ref' => 'other-schema'], ['meta' => ['owner' => ['type' => 'actor', 'id' => 'foreign']]]] as $override) {
        BeamParticle::on('central')->create([...$record->only(['schema_ref', 'payload', 'meta']), ...$override]);
    }
    app()->forgetScopedInstances();
    expect(array_keys(app(StoredPortfolios::class)->all()))->toBe(['central-board'])
        ->and(app(StoredPortfolios::class)->delete('central-board', $actor))->toBeTrue()
        ->and(BeamParticle::on('central')->count())->toBe(2);
    config(['database.default' => 'central']);
});

it('retains a host write gate in addition to the declared shared ability', function () {
    app()->instance(WriteGate::class, new class implements WriteGate
    {
        public function authorizes(Model|string $subject, array $payload, mixed $actor = null): bool
        {
            return false;
        }
    });
    $actor = SyntheticSeatUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);
    $portfolio = ScenarioPortfolioData::from(['id' => 'host-denies', 'label' => 'Denied', 'members' => [['blueprint' => 'demo-quillmere-studio']]]);
    expect(fn () => app(StoredPortfolios::class)->save($portfolio, $actor))->toThrow(WriteNotAuthorized::class)
        ->and(BeamParticle::count())->toBe(0);
});
