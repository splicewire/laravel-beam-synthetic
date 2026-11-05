<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Rushing\Commerce\Billing\BillingPeriod;
use Splicewire\Beam\Commerce\Bill;
use Splicewire\Beam\Commerce\Billing\Components\PerSeatFees;
use Splicewire\Beam\Commerce\CentralUsageSummary;
use Splicewire\Beam\Commerce\Plan;
use Splicewire\Beam\Commerce\Subscription;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
use Splicewire\Beam\Synthetic\Data\SyntheticTenantBlueprintData;
use Splicewire\Beam\Synthetic\Extras\MaterializesSyntheticExtras;
use Splicewire\Beam\Synthetic\Extras\SyntheticExtrasRegistry;
use Splicewire\Beam\Synthetic\Extras\SyntheticPlane;
use Splicewire\Beam\Synthetic\Extras\SyntheticTenantContext;
use Splicewire\Beam\Synthetic\Materialize\MissingSyntheticPlan;
use Splicewire\Beam\Synthetic\Materialize\SyntheticTenantConflict;
use Splicewire\Beam\Synthetic\Materialize\SyntheticTenantMaterializer;
use Splicewire\Beam\Synthetic\Roster\SyntheticRoster;
use Splicewire\Beam\Synthetic\ScenarioExpander;
use Splicewire\Beam\Synthetic\Tests\Support\SyntheticHost;
use Splicewire\Beam\Synthetic\Tests\Support\SyntheticSeatUser;
use Splicewire\Beam\Tenancy\Models\TenantMembership;
use Splicewire\Beam\Tenancy\Tenant;
use Stancl\Tenancy\Events\TenantCreated;

uses(SyntheticHost::class);

const DEMO_BLUEPRINTS = ['demo-quillmere-studio', 'demo-tessaline-works', 'demo-orrowby-hart'];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $this->seedDemoPlans();
});

function materializeBlueprint(string $id, bool $bills = false): array
{
    return app(SyntheticTenantMaterializer::class)->materialize(
        SyntheticRoster::ofBlueprint(app(BlueprintRegistry::class)->get($id)),
        bills: $bills,
    );
}

/** Every row the materializer writes, as one comparable snapshot. */
function syntheticSnapshot(): array
{
    return [
        'tenants' => Tenant::query()->orderBy('id')->get()->map(fn (Tenant $t) => [$t->id, $t->name, $t->isSynthetic(), $t->plan_slug, $t->synthetic_blueprint])->all(),
        'users' => SyntheticSeatUser::query()->orderBy('email')->pluck('email')->all(),
        'seats' => TenantMembership::query()->orderBy('tenant_id')->orderBy('accepted_at')->get()
            ->map(fn ($m) => [$m->tenant_id, $m->role, (string) $m->accepted_at, (string) $m->removed_at])->all(),
        'subscriptions' => Subscription::query()->orderBy('party_id')->get()
            ->map(fn ($s) => [$s->party_type, $s->party_id, $s->plan_id, (string) $s->started_at, (string) $s->ended_at])->all(),
        'usage' => CentralUsageSummary::with('tenants:id')->orderBy('month')->get()
            ->map(fn ($u) => [$u->tenants->pluck('id')->all(), $u->month, (string) $u->total_cost_usd, $u->total_tokens, $u->meters])
            ->sortBy(fn ($row) => $row[0][0].$row[1])->values()->all(),
        'bills' => Bill::query()->orderBy('party_id')->orderBy('billing_period')->get()
            ->map(fn ($b) => [$b->party_id, $b->billing_period, (string) $b->total_usd])->all(),
    ];
}

it('materializes each Demo blueprint as a tenant flagged synthetic, on its own plan', function () {
    foreach (DEMO_BLUEPRINTS as $id) {
        $results = materializeBlueprint($id);

        expect($results)->toHaveCount(1)
            ->and($results[0]->tenantId)->toBe($id)
            ->and($results[0]->created)->toBeTrue();
    }

    expect(Tenant::query()->synthetic()->pluck('id')->sort()->values()->all())->toBe(collect(DEMO_BLUEPRINTS)->sort()->values()->all())
        ->and(Tenant::query()->notSynthetic()->count())->toBe(0);

    foreach (DEMO_BLUEPRINTS as $id) {
        $blueprint = app(BlueprintRegistry::class)->get($id);
        $subscription = Subscription::query()->where('party_id', $id)->sole();

        expect($subscription->party_type)->toBe('tenant')
            ->and($subscription->plan_id)->toBe(Plan::where('slug', $blueprint->planSlug)->value('id'))
            ->and($subscription->started_at->format('Y-m'))->toBe('2025-11')
            ->and($subscription->ended_at)->toBeNull();
    }
});

it('seats exactly the seat curve in every closed month, as per-seat billing counts them', function () {
    $expander = app(ScenarioExpander::class);

    foreach (DEMO_BLUEPRINTS as $id) {
        [$result] = materializeBlueprint($id);
        $roster = SyntheticRoster::ofBlueprint(app(BlueprintRegistry::class)->get($id));
        $series = $roster->series($expander, $roster->members[0], $result->months);
        $tenant = Tenant::find($id);

        expect($result->months)->toHaveCount(12)
            ->and($result->months[0])->toBe('2025-11')
            ->and($result->months[array_key_last($result->months)])->toBe('2026-10');

        foreach ($series as $month => $point) {
            $active = (new PerSeatFees($tenant))->measure($tenant, BillingPeriod::fromString($month))->requireActiveSeats();

            expect($active)->toBe($point->seatCount, "{$id} {$month}");
        }

        // The curve really moves, so the equality above is not a constant matching a constant.
        expect(array_unique(array_map(fn ($p) => $p->seatCount, $series)))->not->toHaveCount(1);
    }
});

it('records one usage summary per month on the blueprint meter, with the curve cost', function () {
    [$result] = materializeBlueprint('demo-quillmere-studio');
    $roster = SyntheticRoster::ofBlueprint(app(BlueprintRegistry::class)->get('demo-quillmere-studio'));
    $series = $roster->series(app(ScenarioExpander::class), $roster->members[0], $result->months);

    $summaries = CentralUsageSummary::query()->whereHas('tenants', fn ($q) => $q->where('tenants.id', 'demo-quillmere-studio'))->orderBy('month')->get();

    expect($summaries)->toHaveCount(12)
        ->and($summaries->pluck('month')->all())->toBe($result->months);

    foreach ($summaries as $summary) {
        $cost = $series[$summary->month]->usageCostUsd;

        expect($summary->meters)->toHaveKey('openai.tokens')
            ->and((float) $summary->meters['openai.tokens']['cost_usd'])->toBe($cost)
            ->and($summary->meters['openai.tokens']['quantity'])->toBe((int) round($cost * 1000))
            ->and((float) $summary->total_cost_usd)->toBe($cost)
            ->and($summary->total_tokens)->toBe((int) round($cost * 1000));
    }
});

it('bills against a non-token meter the blueprint names, and leaves the legacy token column at zero', function () {
    $blueprint = SyntheticTenantBlueprintData::from([
        ...app(BlueprintRegistry::class)->get('demo-tessaline-works')->toArray(),
        'id' => 'meter-probe',
        'usageMeter' => 'storage.gb',
    ]);

    app(SyntheticTenantMaterializer::class)->materialize(SyntheticRoster::ofBlueprint($blueprint));

    $summary = CentralUsageSummary::query()->whereHas('tenants', fn ($q) => $q->where('tenants.id', 'meter-probe'))->orderBy('month')->firstOrFail();

    expect(array_keys($summary->meters))->toBe(['storage.gb'])
        ->and($summary->total_tokens)->toBe(0);
});

it('is idempotent: a second run rewrites the same rows to the same values', function () {
    foreach (DEMO_BLUEPRINTS as $id) {
        materializeBlueprint($id, bills: true);
    }
    $first = syntheticSnapshot();

    $again = [];
    foreach (DEMO_BLUEPRINTS as $id) {
        $again[] = materializeBlueprint($id, bills: true)[0];
    }

    expect(syntheticSnapshot())->toBe($first)
        ->and(array_map(fn ($r) => $r->created, $again))->toBe([false, false, false])
        ->and(array_map(fn ($r) => $r->billsGenerated, $again))->toBe([[], [], []])
        ->and(array_map(fn ($r) => count($r->billsSkipped), $again))->toBe([12, 12, 12]);

    // The snapshot is not vacuous.
    expect($first['tenants'])->toHaveCount(3)
        ->and($first['usage'])->toHaveCount(36)
        ->and($first['bills'])->toHaveCount(36)
        ->and(count($first['seats']))->toBeGreaterThan(10);
});

it('generates the closed periods\' bills through the bill generator, priced from the materialized rows', function () {
    [$result] = materializeBlueprint('demo-quillmere-studio', bills: true);

    expect($result->billsGenerated)->toBe($result->months);

    $last = Bill::query()->where('party_id', 'demo-quillmere-studio')->forPeriod('2026-10')->sole();
    $types = array_column($last->line_items, 'component_type');

    expect($types)->toBe(['SubscriptionFee', 'PerSeatFees', 'PerTokenFees'])
        ->and($last->status)->toBe('draft')
        ->and((float) $last->total_usd)->toBeGreaterThan(299.0);
});

it('never bills a real tenant, unlike GenerateAllBills', function () {
    $real = Tenant::create(['id' => 'real-customer', 'name' => 'Real', 'slug' => 'real-customer']);
    Subscription::create(['party_type' => 'tenant', 'party_id' => $real->id, 'plan_id' => Plan::where('slug', 'starter')->value('id'), 'started_at' => '2025-01-01']);

    materializeBlueprint('demo-tessaline-works', bills: true);

    expect(Bill::query()->where('party_id', 'real-customer')->count())->toBe(0)
        ->and(Bill::query()->where('party_id', 'demo-tessaline-works')->count())->toBe(12);
});

it('fails loudly, naming the slug and writing nothing, when the host has no plan the blueprint names', function () {
    Plan::where('slug', 'professional')->delete();

    expect(fn () => materializeBlueprint('demo-quillmere-studio'))
        ->toThrow(MissingSyntheticPlan::class, "'professional' (named by demo-quillmere-studio)");

    expect(Tenant::count())->toBe(0)
        ->and(Subscription::count())->toBe(0)
        ->and(CentralUsageSummary::count())->toBe(0);
});

it('names every missing slug of a portfolio at once, and lists the plans the host has', function () {
    Plan::query()->delete();
    Plan::create(['name' => 'Free', 'slug' => 'free', 'settings' => ['components' => []]]);

    $roster = SyntheticRoster::ofPortfolio(app(PortfolioRegistry::class)->get('demo-mixed-book'), app(BlueprintRegistry::class));

    expect(fn () => app(SyntheticTenantMaterializer::class)->materialize($roster))
        ->toThrow(MissingSyntheticPlan::class, "'professional' (named by demo-quillmere-studio, demo-orrowby-hart); 'starter' (named by demo-tessaline-works). Its plans are: free.");
});

it('refuses to rewrite a real tenant that holds the id', function () {
    Tenant::create(['id' => 'demo-orrowby-hart', 'name' => 'Not synthetic', 'slug' => 'demo-orrowby-hart']);

    expect(fn () => materializeBlueprint('demo-orrowby-hart'))->toThrow(SyntheticTenantConflict::class, 'is not synthetic');

    expect(Tenant::find('demo-orrowby-hart')->isSynthetic())->toBeFalse()
        ->and(Subscription::count())->toBe(0);
});

it('refuses an id that holds a synthetic tenant told from another story', function () {
    $tenant = new Tenant(['id' => 'demo-orrowby-hart', 'name' => 'Other', 'slug' => 'demo-orrowby-hart']);
    $tenant->synthetic_blueprint = 'some-other-blueprint';
    $tenant->markSynthetic()->save();

    expect(fn () => materializeBlueprint('demo-orrowby-hart'))
        ->toThrow(SyntheticTenantConflict::class, "materialized from blueprint 'some-other-blueprint', not blueprint 'demo-orrowby-hart'");
});

it('materializes a portfolio as each member\'s count of tenants, each starting at its offset', function () {
    $roster = SyntheticRoster::ofPortfolio(app(PortfolioRegistry::class)->get('demo-mixed-book'), app(BlueprintRegistry::class));
    $results = app(SyntheticTenantMaterializer::class)->materialize($roster);

    expect(array_map(fn ($r) => $r->tenantId, $results))->toBe([
        'demo-quillmere-studio-1', 'demo-quillmere-studio-2', 'demo-quillmere-studio-3',
        'demo-tessaline-works-1', 'demo-tessaline-works-2', 'demo-tessaline-works-3', 'demo-tessaline-works-4',
        'demo-orrowby-hart-1', 'demo-orrowby-hart-2',
    ]);

    // The window is the longest offset + window (5 + 12 = 17 months); each member stays to its end.
    expect($results[0]->months[0])->toBe('2025-06')
        ->and(count($results[0]->months))->toBe(17)
        ->and($results[3]->months[0])->toBe('2025-08')
        ->and($results[7]->months[0])->toBe('2025-11')
        ->and($results[7]->months[array_key_last($results[7]->months)])->toBe('2026-10');

    $tenant = Tenant::find('demo-tessaline-works-2');
    expect($tenant->isSynthetic())->toBeTrue()
        ->and($tenant->synthetic_portfolio)->toBe('demo-mixed-book')
        ->and($tenant->name)->toBe('Tessaline Works 2');

    // Copies of one blueprint replay different curves (the tenant id seeds them) and never share a seat user.
    $usage = fn (string $id) => CentralUsageSummary::query()->whereHas('tenants', fn ($q) => $q->where('tenants.id', $id))->orderBy('month')->pluck('total_cost_usd')->all();
    expect($usage('demo-quillmere-studio-1'))->not->toBe($usage('demo-quillmere-studio-2'))
        ->and(SyntheticSeatUser::where('email', 'demo-quillmere-studio-2-seat-1@quillmere.example')->exists())->toBeTrue()
        ->and(SyntheticSeatUser::where('email', 'seat-1@quillmere.example')->exists())->toBeFalse();
});

it('releases the seats and ends the subscription at a hard churn', function () {
    $blueprint = SyntheticTenantBlueprintData::from([
        ...app(BlueprintRegistry::class)->get('demo-quillmere-studio')->toArray(),
        'id' => 'churn-probe',
        'endedAtMonth' => 8,
    ]);

    [$result] = app(SyntheticTenantMaterializer::class)->materialize(SyntheticRoster::ofBlueprint($blueprint), bills: true);
    $tenant = Tenant::find('churn-probe');

    expect(Subscription::query()->where('party_id', 'churn-probe')->sole()->ended_at->format('Y-m'))->toBe('2026-06');

    $active = fn (string $month) => (new PerSeatFees($tenant))->measure($tenant, BillingPeriod::fromString($month))->requireActiveSeats();
    expect($active('2026-06'))->toBeGreaterThan(0)
        ->and($active('2026-07'))->toBe(0)
        ->and($result->billsGenerated)->toBe(array_slice($result->months, 0, 8));
});

it('runs a central extra after the generic writes, and lets it set attributes before the provisioning save', function () {
    $seen = [];
    Event::listen(TenantCreated::class, function (TenantCreated $event) use (&$seen) {
        $seen[] = $event->tenant->scaffold_pack_slugs;
    });

    app(SyntheticExtrasRegistry::class)->register('probe', CentralProbeExtra::class);
    [$result] = materializeBlueprint('demo-quillmere-studio');

    expect($result->extras)->toBe(['probe'])
        ->and($seen)->toBe([['probe-pack']])
        ->and(CentralProbeExtra::$calls)->toBe([['demo-quillmere-studio', 12, true]]);
});

it('flags the tenant before the save that fires the provisioning pipeline, so no step sees it unflagged', function () {
    $seen = [];
    Event::listen(TenantCreated::class, function (TenantCreated $event) use (&$seen) {
        $seen[] = Tenant::find($event->tenant->getKey())->isSynthetic();
    });

    materializeBlueprint('demo-tessaline-works');

    expect($seen)->toBe([true]);
});

it('re-flags the tenant when a pipeline step rewrites its data through another instance', function () {
    // A provisioning step saves the shared `data` column from its own instance, without the flag.
    Event::listen(TenantCreated::class, function (TenantCreated $event) {
        $own = Tenant::find($event->tenant->getKey());
        $own->markSynthetic(false)->save();
    });

    materializeBlueprint('demo-tessaline-works');

    expect(Tenant::find('demo-tessaline-works')->isSynthetic())->toBeTrue();
});

it('refuses a tenant-plane extra, naming the tenant, when the tenant has no storage', function () {
    app(SyntheticExtrasRegistry::class)->register('in-tenant', TenantProbeExtra::class);

    expect(fn () => materializeBlueprint('demo-orrowby-hart'))
        ->toThrow(SyntheticTenantConflict::class, "The synthetic extra 'in-tenant' writes inside the tenant, but the storage of 'demo-orrowby-hart' is not ready");
});

it('skips an extra that does not apply to the blueprint', function () {
    app(SyntheticExtrasRegistry::class)->register('in-tenant', TenantProbeExtra::class);

    [$result] = materializeBlueprint('demo-quillmere-studio');

    expect($result->extras)->toBe([]);
});

class CentralProbeExtra implements MaterializesSyntheticExtras
{
    /** @var list<array{string, int, bool}> */
    public static array $calls = [];

    public function plane(): SyntheticPlane
    {
        return SyntheticPlane::Central;
    }

    public function appliesTo(SyntheticTenantBlueprintData $blueprint): bool
    {
        return true;
    }

    public function beforeProvisioning(Tenant $tenant, SyntheticTenantBlueprintData $blueprint): void
    {
        $tenant->scaffold_pack_slugs = ['probe-pack'];
    }

    public function materialize(SyntheticTenantContext $context): void
    {
        self::$calls[] = [
            (string) $context->tenant->getKey(),
            CentralUsageSummary::query()->whereHas('tenants', fn ($q) => $q->where('tenants.id', $context->tenant->getKey()))->count(),
            $context->subscription->exists,
        ];
    }
}

class TenantProbeExtra implements MaterializesSyntheticExtras
{
    public function plane(): SyntheticPlane
    {
        return SyntheticPlane::Tenant;
    }

    public function appliesTo(SyntheticTenantBlueprintData $blueprint): bool
    {
        return $blueprint->id === 'demo-orrowby-hart';
    }

    public function beforeProvisioning(Tenant $tenant, SyntheticTenantBlueprintData $blueprint): void {}

    public function materialize(SyntheticTenantContext $context): void {}
}
