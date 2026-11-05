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

The generic materializer, its commands and the financial-projection engine follow.

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

## Registries

This package declares two registries, each documented under `docs/registries/`:

- [`beam.synthetic.blueprints`](docs/registries/beam.synthetic.blueprints.mdx): the synthetic-tenant
  blueprints a host can replay, keyed by id.
- [`beam.synthetic.portfolios`](docs/registries/beam.synthetic.portfolios.mdx): named sets of
  blueprints, keyed by id.

It registers into none.

## Testing

Run `composer test`. The curve is pinned by a golden fixture, `tests/fixtures/synthetic/scenario-curves.json`,
which the suite reads and never writes.
