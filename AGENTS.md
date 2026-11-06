> You are in **splicewire/laravel-beam-synthetic** — synthesize mode for Beam hosts: the curve kernel, blueprints, the materializer and the financial projection.

A Laravel package (`Splicewire\Beam\Synthetic\*`). It lets a Beam host fill itself with synthetic
tenants and project its own numbers forward, so a board dashboard can show a scenario or a forecast
without inventing a second pricing or revenue path. Beam tier, MIT.

## What is here now

- `Curve\`: the neutral curve kernel (`CurveKernel`, `CurveEnvelope`, `CurvePeriod`, `CurvePoint`,
  `GrowthShape`). It imports only PHP's `Random` engine; `tests/Curve/CurveKernelPurityTest.php`
  reads its `use` lines and fails on anything else.
- `Data\` and `Enums\`: the blueprint family, lifted from tower and the flagship.
  `SyntheticTenantBlueprintData` owns replay validation (an unknown scenario slug is refused in
  `from()`), reads the old `targetMonthlyTokenCostUsd` key for one release, and defaults
  `usageMeter` from `beam.synthetic.usage_meter`.
- `ScenarioExpander`: the billing reading of the kernel (months, usage cost, seats).
- `Golden\GoldenCurveCases` plus `tests/fixtures/synthetic/scenario-curves.json`: the golden curves,
  byte-identical to the fixture captured in the flagship before the lift.
- `Blueprints\`: `BlueprintRegistry` (`beam.synthetic.blueprints`) and `PortfolioRegistry`
  (`beam.synthetic.portfolios`), filled from `beam.synthetic.blueprint_paths` /
  `portfolio_paths` by a `DirectoryRegistrar`, and the Demo set under `resources/synthetic/demo/`.
- `Store\`: `StoredPortfolios` (scoped) and the `'central'` `StoredPortfolio` model over
  `beam_synthetic_portfolios` (the migration stub `database/migrations/create_beam_synthetic_portfolios_table.php.stub`,
  published as `beam-synthetic-migrations` and registered into beam's install manifest). Portfolios saved at
  runtime (the exec board's scenario builder). `PortfolioRegistry::get()`/`all()` read declared portfolios, then
  stored ones; a declared id always wins (saving one is refused; a shadowed row is never read). Rows are
  re-validated on read; saves and deletes go to the central activity trail (`synthetic-portfolios`).
- `Roster\`: `SyntheticRoster` (who a run is about: one blueprint as one tenant, or a portfolio as
  `{blueprint}-{k}` copies at their offsets; history months back from now, forward months from an
  anchor, deliberately two methods) and the pure `ScenarioProjector`.
- `Materialize\SyntheticTenantMaterializer`: writes a roster's history as synthetic tenants (tenant,
  plan subscription, seats per the seat curve, usage on the blueprint's meter, optional bills). It
  checks ids, plan slugs and id conflicts before any write.
- `Extras\`: `MaterializesSyntheticExtras`, `SyntheticPlane`, `SyntheticTenantContext` and
  `SyntheticExtrasRegistry` (`beam.synthetic.materializers`), the host hook.
- `Console\`: `synthetic:materialize`, `synthetic:project` (zero queries) and
  `synthetic:capture-curves` (the golden fixture's regeneration command).

- `Projection\`: the pure financial-projection engine. `ProjectionEngine::project($baseline,
  $assumptions)` turns a `ProjectionBaseline` (every party's `PartyPeriodRevenue` at one closed
  anchor month) and `ProjectionAssumptions` (churn, expansion, growth, `PriceChange`s, a horizon)
  into a `RevenueProjection` whose `periods()` and `rows()` go to beam-commerce's
  `RevenueMovements::from()` unchanged. It imports only its own namespace,
  `InvalidArgumentException` and commerce's import-free `PartyPeriodRevenue`;
  `tests/Projection/ProjectionEnginePurityTest.php` reads its `use` lines and fails on anything else.
- `Baseline\MeasuredBaselineLoader`: the one part of a projection that reads. It loads a closed
  month from commerce's `MeasuredRevenueLedger` as a `ProjectionBaseline`.

What stays in the host: the LLM blueprint generator and its prompt, the compliance extra, and any
SecondParty blueprints.

## Rules that are easy to get backwards

- **Tiering.** This package requires beam, beam-tenancy and beam-commerce DOWN. It must never
  require `splicewire/tower`, `splicewire/laravel-satellite` or any `splicewire/laravel-tower-*`, and
  it must never reach `splicewire/laravel-beam-exec`, which requires it. The fences are declared in
  `composer.json` `extra.package-topology` and pinned by `tests/Topology/`.
- **Why not tenancy.** beam-tenancy may not require beam-commerce, so a materializer that writes
  subscriptions and bills cannot live there.
- **The provider never hand-lists steps.** A concern is a `Wires*` trait whose methods carry
  `#[Chained('register')]` or `#[Chained('boot')]` with a declared `order:`. `ScopesRegistrant`
  records this package as the registrant of every registry write in both phases.
- **Config.** A key lands in the same commit as the code that reads it.
- **The golden fixture is never rewritten by a test.** If a curve change is deliberate, regenerate
  it with `vendor/bin/testbench synthetic:capture-curves --write` and review the diff. Its rows keep the pre-rename key `token_cost_usd`; that is
  its frozen byte format, not a missed rename.
- **The kernel stays neutral.** No month, money, seat, tenant or plan vocabulary under `Curve\`; the
  billing reading lives in `ScenarioExpander` and `Data\`.
- **Registry docs.** Every `#[IsRegistry]` root has a page under `docs/registries/`, checked by
  `tests/Registries/RegistryDocsAdoptionTest.php`. The facts block must equal a fresh
  `RegistryFacts::render()` byte for byte.

- **The projection engine stays pure.** Nothing under `src/Projection/` may import a framework,
  database or clock type, or call `now()`, `config()` or a random source. The database read is the
  loader's, in `src/Baseline/`. A caller holding ledger-shaped rows (a board source's `revenue()`)
  calls `ProjectionBaseline::fromRows()` itself: this package must never reach beam-exec, so the
  loader cannot name `BoardSource`.
- **The projection reads nothing.** `ScenarioProjector` resolves no model or connection, and neither does
  `synthetic:project` for a declared blueprint or portfolio; `tests/Console/ProjectCommandTest.php` holds them to
  zero queries. Resolving a STORED portfolio id (or listing with no argument) reads the store first, two queries,
  before the projection runs; the projection itself still reads nothing.
- **A declared portfolio is never shadowed by a stored one.** Keep `PortfolioRegistry::declares()`, `declared()`
  and a declared-id `get()` query-free: the Scenario lens's default portfolio and every file lookup rely on it
  (`tests/Store/StoredPortfoliosTest.php`).
- **The materializer bills only its own tenants.** `--bills` goes through `BillGenerator` per
  synthetic tenant with `GenerateAllBills`' already-billed skip, never through `GenerateAllBills`
  itself, which bills every subscription in the host.
- **Storage belongs to the host's pipeline.** The materializer provisions a tenant's storage only
  where the host declares no provisioning pipeline (`beam.synthetic.provision_storage`).

## Vendored family-package conventions

Before editing through `vendor/` into another family package, read that package's own `AGENTS.md`.

## Testing

`composer test` (Pest on Testbench). Install through the local overlay: `composer.local.json` is
generated by the ecosystem's `pnpm repos` and stays gitignored.
