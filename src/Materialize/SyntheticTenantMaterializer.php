<?php

namespace Splicewire\Beam\Synthetic\Materialize;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Rushing\Commerce\Billing\BillingPeriod;
use Splicewire\Beam\Commerce\Bill;
use Splicewire\Beam\Commerce\Billing\BillGenerator;
use Splicewire\Beam\Commerce\Billing\Components\PerSeatFees;
use Splicewire\Beam\Commerce\Billing\Components\PerTokenFees;
use Splicewire\Beam\Commerce\Billing\MeterRegistry;
use Splicewire\Beam\Commerce\CentralUsageSummary;
use Splicewire\Beam\Commerce\Ops\GenerateAllBills;
use Splicewire\Beam\Commerce\Plan;
use Splicewire\Beam\Commerce\Subscription;
use Splicewire\Beam\Synthetic\Data\ScenarioMonthData;
use Splicewire\Beam\Synthetic\Extras\MaterializesSyntheticExtras;
use Splicewire\Beam\Synthetic\Extras\SyntheticExtrasRegistry;
use Splicewire\Beam\Synthetic\Extras\SyntheticPlane;
use Splicewire\Beam\Synthetic\Extras\SyntheticTenantContext;
use Splicewire\Beam\Synthetic\Roster\RosterMember;
use Splicewire\Beam\Synthetic\Roster\SyntheticRoster;
use Splicewire\Beam\Synthetic\ScenarioExpander;
use Splicewire\Beam\Tenancy\Database\Seeders\DemoTenantSeeder;
use Splicewire\Beam\Tenancy\Models\TenantMembership;
use Splicewire\Beam\Tenancy\Provisioning\TenantProvisioningStepRegistry;
use Splicewire\Beam\Tenancy\Tenant;
use Splicewire\Beam\Tenancy\TenantProvisioningStatus;
use Splicewire\Beam\Tenancy\TenantSlug;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Jobs\CreateDatabase;

/**
 * Writes a {@see SyntheticRoster}'s history into the host as real, flagged rows: each member becomes a tenant marked
 * synthetic, on its blueprint's plan, with seats that follow its seat curve, metered usage that follows its usage
 * curve, and (on request) the closed periods' bills. A host's own steps run as registered extras.
 *
 * Every write goes through the same models and generator a real tenant's do, so a board reading Live numbers cannot
 * tell the two apart except by the flag, and a pricing change reaches both.
 *
 * ## Checked before anything is written
 *
 * - Every tenant id holds to the workspace-slug grammar ({@see TenantSlug}), because it becomes a schema name, a
 *   subdomain and a cache prefix.
 * - Every plan slug the blueprints name resolves against the host's own plans ({@see MissingSyntheticPlan}).
 * - No id belongs to a real tenant, or to a synthetic tenant told from another blueprint or portfolio
 *   ({@see SyntheticTenantConflict}).
 *
 * ## Per tenant, in order
 *
 * 1. **Provision through the real tenancy path.** The tenant is saved through `firstOrNew()`, so a new one fires
 *    stancl's `TenantCreated` and the host's declared provisioning pipeline runs, as for any tenant. It is marked
 *    synthetic before that save (so no step of the pipeline sees an unflagged row) and again after it, from a fresh
 *    read, because pipeline steps write the shared `data` column through their own instances.
 * 2. **Storage.** The host's pipeline owns the tenant's storage. Only where the host declares no pipeline at all does
 *    the materializer provision it, through stancl's own `CreateDatabase` and a `tenants:migrate` scoped to this one
 *    tenant (the {@see DemoTenantSeeder} shape), so the row is one a tenant
 *    sweep can connect to. `beam.synthetic.provision_storage` turns that off.
 * 3. **The plan subscription**, started on the first materialized month and ended at the blueprint's churn.
 * 4. **Seats**, on the central `tenant_users` pivot. Seat `k` is accepted in the first month the seat curve reaches
 *    `k` and released at the end of the last month it holds `k`, so the seats active in each closed period, as
 *    {@see PerSeatFees} counts them, equal the curve.
 * 5. **Usage**, one central usage summary per month on the blueprint's meter (`beam.synthetic.usage_meter` when it
 *    names none). Other meters already on the summary are kept.
 * 6. **Bills**, with `$bills`: every closed period the subscription covers, through the {@see BillGenerator} that
 *    {@see GenerateAllBills} runs, with that op's rule that an already-billed period is skipped. It does not call
 *    `GenerateAllBills` itself, because that op bills every subscription in the host, real tenants included.
 * 7. **Extras**, in registration order.
 *
 * ## Idempotent
 *
 * A re-run in the same month finds every row and rewrites it to the same values: no tenant, seat, user, summary or
 * bill is duplicated. The window is anchored on now, so a run in a later month moves the story forward a month.
 */
class SyntheticTenantMaterializer
{
    /** The pivot role a synthetic seat carries: an ordinary member, never an owner or admin. */
    private const SEAT_ROLE = 'member';

    public function __construct(
        private readonly ScenarioExpander $expander,
        private readonly SyntheticExtrasRegistry $extras,
        private readonly BillGenerator $bills,
        private readonly Config $config,
    ) {}

    /**
     * @return list<MaterializedTenant> one per member present in the window, in roster order
     */
    public function materialize(SyntheticRoster $roster, bool $bills = false, ?CarbonImmutable $now = null): array
    {
        $window = $roster->historyMonths($now ?? CarbonImmutable::now());

        $this->assertIds($roster);
        $plans = $this->resolvePlans($roster);
        $this->assertNoConflicts($roster);

        $results = [];

        foreach ($roster->members as $member) {
            $months = $roster->monthsFor($member, $window);

            if ($months === []) {
                continue;
            }

            $results[] = $this->materializeMember($roster, $member, $months, $plans[$member->blueprint->planSlug], $bills);
        }

        return $results;
    }

    /**
     * Resolve every plan slug the roster names against this host's plans, or fail naming each missing one.
     *
     * @return array<string, Plan> slug => plan
     */
    public function resolvePlans(SyntheticRoster $roster): array
    {
        $slugs = $roster->planSlugs();
        $plans = Plan::query()->whereIn('slug', $slugs)->get()->keyBy('slug')->all();

        $missing = [];
        foreach ($roster->members as $member) {
            $slug = $member->blueprint->planSlug;

            if (! isset($plans[$slug])) {
                $missing[$slug] ??= [];
                if (! in_array($member->blueprint->id, $missing[$slug], true)) {
                    $missing[$slug][] = $member->blueprint->id;
                }
            }
        }

        if ($missing !== []) {
            throw MissingSyntheticPlan::for($missing, Plan::query()->orderBy('slug')->pluck('slug')->all());
        }

        return $plans;
    }

    private function assertIds(SyntheticRoster $roster): void
    {
        foreach ($roster->members as $member) {
            TenantSlug::assertValid($member->tenantId);
        }
    }

    private function assertNoConflicts(SyntheticRoster $roster): void
    {
        $model = $this->tenantModel();
        $ids = array_map(fn (RosterMember $m): string => $m->tenantId, $roster->members);
        $existing = $model::query()->whereKey($ids)->get()->keyBy(fn (Tenant $t): string => (string) $t->getKey());

        foreach ($roster->members as $member) {
            $tenant = $existing->get($member->tenantId);

            if ($tenant === null) {
                continue;
            }

            if (! $tenant->isSynthetic()) {
                throw SyntheticTenantConflict::realTenant($member->tenantId);
            }

            $theirs = self::story($tenant->synthetic_blueprint, $tenant->synthetic_portfolio);
            $ours = self::story($member->blueprint->id, $member->portfolioId);

            // A tenant materialized before the story was recorded carries no blueprint: it is adopted, not refused.
            if ($tenant->synthetic_blueprint !== null && $theirs !== $ours) {
                throw SyntheticTenantConflict::otherStory($member->tenantId, $theirs, $ours);
            }
        }
    }

    private static function story(?string $blueprint, ?string $portfolio): string
    {
        return $portfolio === null ? "blueprint '{$blueprint}'" : "blueprint '{$blueprint}' in portfolio '{$portfolio}'";
    }

    /**
     * @param  list<string>  $months
     */
    private function materializeMember(SyntheticRoster $roster, RosterMember $member, array $months, Plan $plan, bool $withBills): MaterializedTenant
    {
        $blueprint = $member->blueprint;
        $extras = array_filter($this->extras->extras(), fn (MaterializesSyntheticExtras $extra): bool => $extra->appliesTo($blueprint));

        [$tenant, $created] = $this->provision($member, $extras);
        $storageReady = $this->ensureStorage($tenant);

        $series = $roster->series($this->expander, $member, $months);
        $startedAt = CarbonImmutable::createFromFormat('!Y-m', $months[0])->startOfMonth();
        $endedAt = $blueprint->envelope($startedAt)->endedAt;

        $subscription = $this->subscribe($tenant, $plan, $startedAt, $endedAt);
        $seats = $this->seat($tenant, $member, $series, $endedAt);
        $this->recordUsage($tenant, $blueprint->usageMeter, $series);

        [$generated, $skipped] = $withBills ? $this->bill($tenant, $subscription, $months) : [[], []];

        $context = new SyntheticTenantContext($tenant, $blueprint, $member, $months, $series, $subscription);
        foreach ($extras as $name => $extra) {
            $this->runExtra($name, $extra, $context, $storageReady);
        }

        return new MaterializedTenant(
            tenantId: $member->tenantId,
            blueprintId: $blueprint->id,
            created: $created,
            planSlug: $plan->slug,
            months: $months,
            seats: $seats,
            usageMonths: count($series),
            storageReady: $storageReady,
            billsGenerated: $generated,
            billsSkipped: $skipped,
            extras: array_map('strval', array_keys($extras)),
        );
    }

    /**
     * @param  array<string, MaterializesSyntheticExtras>  $extras
     * @return array{0: Tenant, 1: bool} the tenant, and whether this run created it
     */
    private function provision(RosterMember $member, array $extras): array
    {
        $blueprint = $member->blueprint;
        $model = $this->tenantModel();

        /** @var Tenant $tenant */
        $tenant = $model::firstOrNew(['id' => $member->tenantId]);
        $created = ! $tenant->exists;

        $tenant->name = $member->name;
        $tenant->slug = $member->tenantId;
        $tenant->owner_email = $blueprint->ownerEmail;
        $tenant->plan_slug = $blueprint->planSlug;
        $tenant->synthetic_blueprint = $blueprint->id;
        $tenant->synthetic_portfolio = $member->portfolioId;
        $tenant->markSynthetic();

        foreach ($extras as $extra) {
            $extra->beforeProvisioning($tenant, $blueprint);
        }

        $tenant->save();

        // Pipeline steps write `data` through their own instances; re-read, and re-assert the flag if one dropped it.
        $tenant->refresh();
        if (! $tenant->isSynthetic()) {
            $tenant->markSynthetic()->save();
        }

        return [$tenant, $created];
    }

    /** Whether the tenant's own storage exists once this returns. See the class docblock, step 2. */
    private function ensureStorage(Tenant $tenant): bool
    {
        if (! $tenant instanceof TenantWithDatabase) {
            return false;
        }

        $manager = $this->databaseManager($tenant);

        if ($manager === null) {
            return false;
        }

        if ($manager->databaseExists((string) $tenant->database()->getName())) {
            return true;
        }

        if (! $this->config->get('beam.synthetic.provision_storage', true) || $this->hostDeclaresPipeline()) {
            return false;
        }

        // stancl's own job, run inline. `dispatchNow`, not `dispatchSync`: the sync queue serializes the job and
        // re-fetches the tenant, so `tenancy_db_name` would be stamped on another instance (see DemoTenantSeeder).
        app(Dispatcher::class)->dispatchNow(new CreateDatabase($tenant));

        if (array_key_exists('tenants:migrate', Artisan::all())) {
            $exit = Artisan::call('tenants:migrate', ['--tenants' => [(string) $tenant->getKey()]]);

            if ($exit !== 0) {
                throw new RuntimeException(sprintf(
                    "tenants:migrate exited %d for the synthetic tenant '%s'; its storage is not migrated. %s",
                    $exit,
                    (string) $tenant->getKey(),
                    trim(Artisan::output()),
                ));
            }
        }

        $tenant->refresh();

        if ($tenant->provisioning_status !== TenantProvisioningStatus::Active->value) {
            $tenant->markActive();
        }

        return true;
    }

    /** Whether the host declares the provisioning pipeline its `TenantCreated` listener runs. */
    private function hostDeclaresPipeline(): bool
    {
        $pipeline = (string) $this->config->get('beam.tenancy.provisioning.pipeline', 'default');

        return in_array($pipeline, app(TenantProvisioningStepRegistry::class)->names(), true);
    }

    /**
     * The tenant-database manager for the host's driver, or null when it has none. Read without stancl's typed
     * accessors, which throw a TypeError on a host that never published `config/tenancy.php`.
     */
    private function databaseManager(TenantWithDatabase $tenant): ?TenantDatabaseManager
    {
        $connection = $tenant->getInternal('db_connection')
            ?? $this->config->get('tenancy.database.template_tenant_connection')
            ?? $this->config->get('tenancy.database.central_connection');

        if (! is_string($connection)) {
            return null;
        }

        $class = $this->config->get('tenancy.database.managers.'.$this->config->get("database.connections.{$connection}.driver"));

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        /** @var TenantDatabaseManager $manager */
        $manager = app($class);
        $manager->setConnection($connection);

        return $manager;
    }

    private function subscribe(Tenant $tenant, Plan $plan, CarbonImmutable $startedAt, ?CarbonImmutable $endedAt): Subscription
    {
        // The plan subscription only: a tenant may also carry listing subscriptions, which are never billed here.
        $subscription = Subscription::query()
            ->where('party_type', $tenant->getMorphClass())
            ->where('party_id', $tenant->getKey())
            ->whereNotNull('plan_id')
            ->first() ?? new Subscription(['party_type' => $tenant->getMorphClass(), 'party_id' => $tenant->getKey()]);

        $subscription->fill([
            'plan_id' => $plan->getKey(),
            'commitment_months' => 1,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
        ])->save();

        return $subscription;
    }

    /**
     * Seat users so that the seats active in each month equal the seat curve. Returns how many seats the tenant has.
     *
     * @param  array<string, ScenarioMonthData>  $series
     */
    private function seat(Tenant $tenant, RosterMember $member, array $series, ?CarbonImmutable $endedAt): int
    {
        $months = array_keys($series);
        $last = end($months);
        $peak = max(array_map(fn (ScenarioMonthData $m): int => $m->seatCount, $series));
        $userModel = $this->config->get('auth.providers.users.model');

        foreach (range(1, max(1, $peak)) as $k) {
            $held = array_values(array_filter($months, fn (string $month): bool => $series[$month]->seatCount >= $k));

            if ($held === []) {
                continue;
            }

            $acceptedAt = CarbonImmutable::createFromFormat('!Y-m', $held[0])->startOfMonth();
            $lastHeld = end($held);
            // Released at the end of the last month the curve holds it; still seated when that is the window's end,
            // unless the blueprint churns the tenant later.
            $removedAt = $lastHeld === $last
                ? $endedAt
                : CarbonImmutable::createFromFormat('!Y-m', $lastHeld)->endOfMonth();

            /** @var Model $user */
            $user = $userModel::query()->firstOrCreate(
                ['email' => $this->seatEmail($member, $k)],
                ['name' => "{$member->name} Seat {$k}", 'password' => Hash::make(Str::random(32))],
            );

            $pivot = ['role' => self::SEAT_ROLE, 'accepted_at' => $acceptedAt, 'removed_at' => $removedAt];

            $seated = TenantMembership::query()
                ->where('tenant_id', $tenant->getKey())
                ->where('user_id', $user->getKey())
                ->exists();

            $seated
                ? $tenant->users()->updateExistingPivot($user->getKey(), $pivot)
                : $tenant->users()->attach($user->getKey(), $pivot);
        }

        return $peak;
    }

    /**
     * A blueprint's own tenant keeps the flagship's `seat-{k}@{owner's domain}`; a portfolio copy prefixes its tenant
     * id, so two copies of one blueprint never share a seat user.
     */
    private function seatEmail(RosterMember $member, int $k): string
    {
        $owner = $member->blueprint->ownerEmail;
        $domain = str_contains($owner, '@') ? substr((string) strrchr($owner, '@'), 1) : 'example.test';
        $local = $member->tenantId === $member->blueprint->id ? "seat-{$k}" : "{$member->tenantId}-seat-{$k}";

        return "{$local}@{$domain}";
    }

    /**
     * One usage summary per month, attributed to the tenant, holding the month's usage on `$meter`.
     *
     * @param  array<string, ScenarioMonthData>  $series
     */
    private function recordUsage(Tenant $tenant, string $meter, array $series): void
    {
        foreach ($series as $month => $point) {
            $cost = $point->usageCostUsd;

            $summary = CentralUsageSummary::query()
                ->where('month', $month)
                ->whereHas('tenants', fn ($query) => $query->where('tenants.id', $tenant->getKey()))
                ->first();

            $meters = $summary?->meters ?? [];
            $meters[$meter] = ['quantity' => $this->quantity($meter, $cost), 'cost_usd' => $cost, 'models' => []];

            $attributes = [
                'month' => $month,
                'total_cost_usd' => round(array_sum(array_map(fn (array $m): float => (float) ($m['cost_usd'] ?? 0), $meters)), 6),
                // The legacy token column mirrors only the token meter, which is what reads it.
                'total_tokens' => (int) ($meters[PerTokenFees::fixedMeter()]['quantity'] ?? 0),
                'meters' => $meters,
            ];

            if ($summary !== null) {
                $summary->fill($attributes)->save();

                continue;
            }

            $summary = CentralUsageSummary::create($attributes + ['models' => []]);
            $summary->tenants()->attach($tenant->getKey());
        }
    }

    /**
     * The metered quantity behind a cost: at the meter's declared unit cost when the host declares one, otherwise at
     * `beam.synthetic.usage_units_per_usd`.
     */
    private function quantity(string $meter, float $costUsd): int
    {
        $unitCost = MeterRegistry::unitCostUsd($meter);

        if ($unitCost > 0) {
            return (int) round($costUsd / $unitCost);
        }

        return (int) round($costUsd * (float) $this->config->get('beam.synthetic.usage_units_per_usd', 1000));
    }

    /**
     * @param  list<string>  $months
     * @return array{0: list<string>, 1: list<string>} the periods billed now, and the periods already billed
     */
    private function bill(Tenant $tenant, Subscription $subscription, array $months): array
    {
        $current = (string) BillingPeriod::current();
        $end = $subscription->latestBillablePeriod();

        $generated = [];
        $skipped = [];

        foreach ($months as $month) {
            if ($month >= $current || ($end !== null && $month > $end)) {
                continue;
            }

            $billed = Bill::query()
                ->where('party_type', $tenant->getMorphClass())
                ->where('party_id', $tenant->getKey())
                ->forPeriod($month)
                ->exists();

            if ($billed) {
                $skipped[] = $month;

                continue;
            }

            if ($this->bills->generate($tenant, BillingPeriod::fromString($month)) !== null) {
                $generated[] = $month;
            }
        }

        return [$generated, $skipped];
    }

    private function runExtra(string $name, MaterializesSyntheticExtras $extra, SyntheticTenantContext $context, bool $storageReady): void
    {
        if ($extra->plane() === SyntheticPlane::Central) {
            $extra->materialize($context);

            return;
        }

        if (! $storageReady) {
            throw SyntheticTenantConflict::storageNotReady((string) $context->tenant->getKey(), $name);
        }

        $context->tenant->run(fn () => $extra->materialize($context));
    }

    /** @return class-string<Tenant> */
    private function tenantModel(): string
    {
        $model = $this->config->get('tenancy.tenant_model');

        return is_string($model) && is_a($model, Tenant::class, true) ? $model : Tenant::class;
    }
}
