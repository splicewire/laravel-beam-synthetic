# ADR-0002 — Materializing uses the real tenancy path, and host concerns are extras

**Status:** accepted
**Date:** 2026-11-05
**Repo:** `splicewire/laravel-beam-synthetic`
**Wayfinding:** `.scratch/splicewire/splicewire-ecosystem/exec-board/` (tickets 14, 15, 20).
**Extends:** ADR-0001 (the blueprints and portfolios materialized here).
**Supersedes in part:** `splicewire/splicewire-app docs/adr/0026-synthetic-tenants-via-scenario-replay.md` (the seeder
that wrote synthetic tenants is now a thin call to this materializer).
**Relates to:** `docs/registries/beam.synthetic.materializers.mdx`;
`splicewire/laravel-beam-commerce docs/adr/0007-billing-components-measure-then-price.md` (the `BillGenerator`
`--bills` runs).

## Context

Flagship ADR-0026 asked that a synthetic tenant be provisioned through the exact same path as a real one, so it is
structurally indistinguishable and only its flag says otherwise. The flagship's `SyntheticTenantSeeder` did that with
tenancy and commerce types alone, apart from a compliance step that is Splicewire-specific, and it also hand-wrote a
domain row and the pack slugs the host pipeline reads. A generic materializer has to do the generic part for every
host and leave the rest to the host, without either side reaching around the other.

## Decision

1. **`SyntheticTenantMaterializer` checks everything before it writes anything:** every id against `TenantSlug`;
   every plan slug against the host's plans (`MissingSyntheticPlan` names each missing slug, the blueprints that use
   it and the plans the host has); every id for a conflict (`SyntheticTenantConflict` for a real tenant, or a
   synthetic one told from another blueprint or portfolio). A real tenant is never flagged synthetic.
2. **Then, per tenant, it uses the host's own path:** flag the unsaved tenant and save it, so `TenantCreated` runs the
   host's pipeline; re-flag from a fresh read; the plan subscription; seats on `tenant_users` per the curve (seat k
   accepted the first month the curve reaches k, released after the last month it holds k); one usage summary per
   month on the blueprint's meter; with `--bills`, closed-period bills; then extras.
3. **Storage belongs to the host's pipeline.** The materializer provisions storage only where the host declares no
   pipeline (`beam.synthetic.provision_storage`, default true). Provisioning inline at a host whose pipeline is
   queued would make that pipeline's own `CreateDatabase` fail. The domain row is the pipeline's too.
4. **`--bills` runs `BillGenerator` per synthetic tenant, not `GenerateAllBills`,** with that op's skip for a period
   already billed, so a real tenant's subscription gets no bill from a synthetic run.
5. **Host concerns are extras.** `beam.synthetic.materializers` is a popcorn `#[IsRegistry]` (`name => class`,
   Reject on duplicates) of `MaterializesSyntheticExtras`: `plane()` (`Central` or `Tenant`), `appliesTo(blueprint)`,
   `beforeProvisioning(tenant, blueprint)` (runs before the save that fires the pipeline, so a pack slug the pipeline
   reads is set in time) and `materialize(context)`. A tenant-plane extra runs inside `$tenant->run()` and is refused,
   naming the tenant and the extra, when storage isn't ready. The flagship registers two: `ScaffoldPackExtra` (central)
   and `MerchantComplianceExtra` (tenant).
6. **Portfolio tenants are `{blueprint}-{k}`,** so a portfolio never rewrites its blueprint's own tenant. The tenant
   records `synthetic_blueprint` and `synthetic_portfolio`, and a different story under a taken id is refused. A rerun
   is idempotent.
7. **Usage quantity** is cost divided by the meter's declared unit cost, else cost × `beam.synthetic.usage_units_per_usd`
   (1000, the flagship's ratio).
8. **The commands are the package's:** `synthetic:materialize {blueprint?} {--portfolio=} {--bills}`,
   `synthetic:project {blueprint?} {--portfolio=} {--months=} {--from=} {--json}` (zero queries; the JSON is the
   `ScenarioProjectionData` shape), and `synthetic:capture-curves {--write}`, which regenerates the golden fixture as a
   command, never as a test.

## Consequences

- A host whose pipeline is queued gets a tenant-plane extra refused until storage exists; the flagship's production
  relaunch re-runs the seed once the queue drains.
- `SyntheticRoster` and the pure `ScenarioProjector` are the in-memory reading a Scenario lens shares with this
  materializer, so a scenario and a materialized copy of it tell the same story.
