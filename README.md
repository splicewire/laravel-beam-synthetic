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

- **The materializer** (`SyntheticTenantMaterializer`) and three commands: `synthetic:materialize`
  writes a blueprint or portfolio into the host as synthetic tenants, `synthetic:project` runs one
  forward without touching the database, and `synthetic:capture-curves` regenerates the golden
  curves.
- **A third registry**, `beam.synthetic.materializers`, for a host's own materialization steps.

The financial-projection engine follows.

## Install

Require `splicewire/laravel-beam-synthetic`. It requires `splicewire/laravel-beam`,
`splicewire/laravel-beam-tenancy` and `splicewire/laravel-beam-commerce`. The provider is
auto-discovered. To override config, publish `config/beam/synthetic.php`:

```bash
php artisan vendor:publish --tag=beam-synthetic-config
```

| key | default | what it does |
|---|---|---|
| `beam.synthetic.usage_meter` | `openai.tokens` | The meter a blueprint's usage cost is billed against when it names none. |
| `beam.synthetic.blueprint_paths` | the Demo blueprints | Directories of blueprint files. Declaring your own list replaces the default. |
| `beam.synthetic.portfolio_paths` | the Demo portfolio | Directories of portfolio files. |
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
month, and runs no database query at all.

## Registries

This package declares three registries, each documented under `docs/registries/`:

- [`beam.synthetic.blueprints`](docs/registries/beam.synthetic.blueprints.mdx): the synthetic-tenant
  blueprints a host can replay, keyed by id.
- [`beam.synthetic.portfolios`](docs/registries/beam.synthetic.portfolios.mdx): named sets of
  blueprints, keyed by id.
- [`beam.synthetic.materializers`](docs/registries/beam.synthetic.materializers.mdx): the host's own
  materialization steps, keyed by name.

It registers into none.

## Testing

Run `composer test`. The curve is pinned by a golden fixture, `tests/fixtures/synthetic/scenario-curves.json`,
which the suite reads and never writes. To change the curves deliberately, regenerate it with
`vendor/bin/testbench synthetic:capture-curves --write` and review the diff.
