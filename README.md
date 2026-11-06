# splicewire/laravel-beam-synthetic

Synthesize mode for Beam hosts: fill a host with synthetic tenants built from blueprints, read a
portfolio of them in memory, and project the host's own numbers forward, using the same pricing and
revenue code as its real tenants.

What ships now:

- **The curve kernel** (`Splicewire\Beam\Synthetic\Curve\`). `CurveKernel::expand($envelope, $seed)`
  turns a `CurveEnvelope` (a growth shape, the periods, a scalar target, an integer ramp and an
  optional cutoff) into one `CurvePoint` per period. It is pure and deterministic, and it names no
  framework or domain type, so anything that needs a seeded growth curve can call it.
- **The blueprint family.** `SyntheticTenantBlueprintData` (one synthetic tenant's story),
  `ScenarioEnvelopeData`, `ScenarioMonthData`, the `SyntheticScenario` enum, and
  `ScenarioPortfolioData` (a set of blueprints read together). `ScenarioExpander` reads a blueprint's
  envelope through the kernel into a month-by-month series of usage cost and seats.
- **Two registries** of blueprints and portfolios, filled from directories the host declares, with
  three generic Demo blueprints and a Demo portfolio by default.
- **Stored portfolios** (`Store\StoredPortfolios`): portfolios a host saves at runtime, in the central
  `beam_synthetic_portfolios` table, read by the portfolio registry after the files. The exec board's
  scenario builder writes them.

- **The materializer** (`SyntheticTenantMaterializer`) and three commands: `synthetic:materialize`
  writes a blueprint or portfolio into the host as synthetic tenants, `synthetic:project` runs one
  forward without touching the database, and `synthetic:capture-curves` regenerates the golden
  curves.
- **A third registry**, `beam.synthetic.materializers`, for a host's own materialization steps.

- **The financial-projection engine** (`Splicewire\Beam\Synthetic\Projection\`): pure and
  deterministic, it projects a closed month's revenue forward under churn, expansion, growth and
  price-change assumptions, as rows beam-commerce's `RevenueMovements` aggregates unchanged.

## Install

Require `splicewire/laravel-beam-synthetic`. It requires `splicewire/laravel-beam`,
`splicewire/laravel-beam-tenancy` and `splicewire/laravel-beam-commerce`. The provider is
auto-discovered. To override config, publish `config/beam/synthetic.php`:

```bash
php artisan vendor:publish --tag=beam-synthetic-config
```

To store portfolios at runtime, publish and run the migration (`splicewire:beam:install` does both):

```bash
php artisan vendor:publish --tag=beam-synthetic-migrations
php artisan migrate
```

| key | default | what it does |
|---|---|---|
| `beam.synthetic.usage_meter` | `openai.tokens` | The meter a blueprint's usage cost is billed against when it names none. |
| `beam.synthetic.blueprint_paths` | the Demo blueprints | Directories of blueprint files. Declaring your own list replaces the default. |
| `beam.synthetic.portfolio_paths` | the Demo portfolio | Directories of portfolio files. |
| `beam.synthetic.portfolio_store` | `true` | Also read portfolios stored at runtime (`beam_synthetic_portfolios`). `false` reads files only and never queries; a host without the table reads none. |
| `beam.synthetic.materializers` | `[]` | The host's own materialization steps, `name => class`. |
| `beam.synthetic.usage_units_per_usd` | `1000` | Metered units per dollar of synthetic usage, when the host declares no unit cost for the meter. |
| `beam.synthetic.provision_storage` | `true` | Provision a synthetic tenant's storage where the host declares no provisioning pipeline. |

## Expanding a blueprint

```php
use Carbon\CarbonImmutable;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\ScenarioExpander;

$blueprint = app(BlueprintRegistry::class)->get('demo-quillmere-studio');
$start = CarbonImmutable::now()->startOfMonth()->subMonths($blueprint->windowMonths - 1);
$months = array_map(fn (int $i) => $start->addMonths($i)->format('Y-m'), range(0, $blueprint->windowMonths - 1));

$series = app(ScenarioExpander::class)->expand($blueprint->envelope($start), $blueprint->id, $months);
// ['2026-01' => ScenarioMonthData{month, usageCostUsd, seatCount}, ...]
```

The usage target is `targetMonthlyUsageCostUsd`. Its old name, `targetMonthlyTokenCostUsd`, is read
for one release.

## Materializing synthetic tenants

```bash
php artisan synthetic:materialize demo-quillmere-studio          # one blueprint, as one tenant
php artisan synthetic:materialize --portfolio=demo-mixed-book    # every tenant a portfolio describes
php artisan synthetic:materialize demo-quillmere-studio --bills  # also generate the closed periods' bills
```

Each tenant is saved through the host's own provisioning path and flagged synthetic, then gets its
plan subscription, seats that follow the blueprint's seat curve, one usage summary per month on its
meter, and with `--bills` a draft bill for every closed month. The window is the blueprint's
`windowMonths` closed months before now. Before writing anything, the command checks that every
plan slug the blueprints name exists in the host (the Demo set names `professional` and `starter`),
and that no id belongs to a real tenant. A re-run in the same month rewrites the same rows to the
same values.

A host adds its own steps as extras; see
[`beam.synthetic.materializers`](docs/registries/beam.synthetic.materializers.mdx).

## Projecting

```bash
php artisan synthetic:project                                         # list blueprints and portfolios
php artisan synthetic:project demo-tessaline-works --from=2027-01 --months=6 --json
php artisan synthetic:project --portfolio=demo-mixed-book
```

The projection reads the same members and curves the materializer writes, forward from an anchor
month, and runs no database query at all. Naming a stored portfolio (or listing them) reads the store first:
two queries, before the projection starts.

## How to add a portfolio

A portfolio is a named set of blueprints read together: each member names a blueprint, a count and a
start offset, and the portfolio may state monthly `churnRate` and `expansionRate` for a projection to
borrow. There are two ways to add one, and the registry reads both.

**As a file.** Put a YAML or JSON file in a directory listed in `beam.synthetic.portfolio_paths`
(list `DemoBlueprints::portfolios()` too to keep the Demo one):

```yaml
id: board-upside
label: Board upside
members:
  - blueprint: demo-quillmere-studio
    count: 6
  - blueprint: demo-orrowby-hart
    count: 2
    startOffsetMonths: 4
churnRate: 0.02
```

**At runtime.** Store one through `StoredPortfolios` (the exec board's Scenarios panel does this for
you):

```php
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;
use Splicewire\Beam\Synthetic\Store\StoredPortfolios;

app(StoredPortfolios::class)->save(ScenarioPortfolioData::from([...]), causer: $user);
```

A declared (file) id always wins: saving under one is refused, and a stored row whose id a file later
declares is never read. A stored row is validated again on every read, so one that names a removed
blueprint is left out rather than breaking a reader. Every save and delete is written to the central
activity trail (`log_name` `synthetic-portfolios`). Either way the portfolio is then
`synthetic:project --portfolio=<id>`, `synthetic:materialize --portfolio=<id>`, and on the exec board
the Scenario lens `scenario:<id>`.

## Projecting revenue

```php
use Splicewire\Beam\Commerce\Revenue\RevenueMovements;
use Splicewire\Beam\Synthetic\Baseline\MeasuredBaselineLoader;
use Splicewire\Beam\Synthetic\Projection\PriceChange;
use Splicewire\Beam\Synthetic\Projection\ProjectionAssumptions;
use Splicewire\Beam\Synthetic\Projection\ProjectionEngine;

$baseline = app(MeasuredBaselineLoader::class)->lastClosed(now()->toImmutable());

$projection = app(ProjectionEngine::class)->project($baseline, new ProjectionAssumptions(
    horizonMonths: 12,
    churnRate: 0.02,        // monthly share of paying parties lost
    expansionRate: 0.01,    // monthly change in each remaining party's recurring revenue
    growthRate: 0.04,       // monthly new paying parties, as a share of the paying book
    priceChanges: [new PriceChange('2027-03', 0.10, 'starter')],
));

$movements = RevenueMovements::from($projection->periods(), $projection->rows());
$movements->mrr('2027-06');
```

The loader is the only part that reads the database: it takes one closed month from the live
revenue ledger. Each party's month is held flat and then moved by the assumptions. The engine
itself reads no clock, config or database, and the same inputs give byte-identical output. Rows you
already hold, in the ledger's `PartyPeriodRevenue` shape, go in through
`ProjectionBaseline::fromRows($anchor, $rows)`.

A few rules worth knowing:

- Churn and growth count whole parties. A running remainder carries the fractions between months,
  and both rates apply to the paying parties at the end of the month before. Parties leave in a
  fixed order: the baseline's by party id, then new ones in the order they arrived.
- A negative growth rate means no new parties. It never removes one, because losing parties is
  churn's job.
- New parties are named `projected-0001` and so on. By default each pays the baseline's average
  paying party and uses its average usage.
- A party's recurring revenue never drops below zero. A party at zero keeps its row, and the
  aggregator reads it as churn.
- A price change takes effect in a projected month. One at or before the anchor month is refused,
  because the anchor's prices are already in the baseline.

## Registries

This package declares three registries, each documented under `docs/registries/`:

- [`beam.synthetic.blueprints`](docs/registries/beam.synthetic.blueprints.mdx): the synthetic-tenant
  blueprints a host can replay, keyed by id.
- [`beam.synthetic.portfolios`](docs/registries/beam.synthetic.portfolios.mdx): named sets of
  blueprints, keyed by id; it also reads the portfolios stored at runtime.
- [`beam.synthetic.materializers`](docs/registries/beam.synthetic.materializers.mdx): the host's own
  materialization steps, keyed by name.

It registers into one: `beam.install.steps`, beam's install manifest, so `splicewire:beam:install`
publishes and runs the stored-portfolio migration (`beam-synthetic-migrations`).

## Testing

Run `composer test`. The curve is pinned by a golden fixture, `tests/fixtures/synthetic/scenario-curves.json`,
which the suite reads and never writes. To change the curves deliberately, regenerate it with
`vendor/bin/testbench synthetic:capture-curves --write` and review the diff.
