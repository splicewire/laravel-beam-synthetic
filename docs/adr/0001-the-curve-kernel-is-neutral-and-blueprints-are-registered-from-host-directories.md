# ADR-0001 — The curve kernel is neutral, and blueprints are registered from host directories

**Status:** accepted
**Date:** 2026-11-05
**Repo:** `splicewire/laravel-beam-synthetic`
**Wayfinding:** `.scratch/splicewire/splicewire-ecosystem/exec-board/` (PLAN.md D1, D2, D8, D11; tickets 02, 13, 15).
Owner ruling 2026-11-05: "Accept all recommendations".
**Supersedes in part:** `splicewire/splicewire-app docs/adr/0026-synthetic-tenants-via-scenario-replay.md` (where the
expander, its DTOs and the scenario enum live, and the envelope's token-cost field name).
**Settles:** `.scratch/splicewire/splicewire-app/octane-runtime-substrate/issues/09-relocate-the-curve-kernel-to-tower.md`
(placement of the curve kernel).
**Relates to:** `docs/registries/beam.synthetic.blueprints.mdx`, `docs/registries/beam.synthetic.portfolios.mdx`.

## Context

The flagship's synthetic tenants (flagship ADR-0026) replay committed Blueprints through a pure, seeded
`ScenarioExpander`. The expander lived in the flagship's `app/`, its DTOs and scenario enum in tower, and only the
flagship used them, so no other Beam host could synthesize a book or show a scenario on a board. octane-runtime-substrate
08 had rejected extracting the kernel into `rushing/php-*`; its ticket 09 reframed the question: the expander's math
reads a shape, periods, a scalar target, an integer ramp and a cutoff, and nothing about tenants, tokens, seats or
plans is in the math, only in the names.

## Decision

1. **The kernel is `Splicewire\Beam\Synthetic\Curve\`, with no framework or domain type** (D2): `GrowthShape`
   (`steady-ramp`, `seasonal`, `dip-then-recover`), `CurveEnvelope(shape, periods, scalarTarget, rampFrom, rampTo,
   cutoffPeriod, cycleLength = 12)`, `CurvePeriod(key, phase)` and `CurveKernel::expand(envelope, seed)`. The arithmetic
   and the RNG draw order are the flagship expander's, unchanged; the golden fixture is byte-identical (sha256
   `3a383f4e…5741`, pinned by a test), and a 5,000-envelope differential found 0 differing month points.
2. **The kernel takes one input octane 09's signature did not list.** `seasonal` reads the month of the year from each
   month key, so the math is not calendar-free: a period carries a 0-based `phase` within a `cycleLength`. The cutoff
   compares positions, so the kernel takes it as a period index (`cutoffPeriod`; `null` = none, `-1` = before the
   window). The domain reader, `ScenarioExpander`, derives both from `YYYY-MM` keys.
3. **The domain layer sits above the kernel, in Beam vocabulary.** `SyntheticTenantBlueprintData`,
   `ScenarioEnvelopeData` (no `planSlug`), `ScenarioMonthData`, the `SyntheticScenario` enum, and
   `ScenarioPortfolioData{id, label, members{blueprint, count, startOffsetMonths}, churnRate?, expansionRate?}`. A
   `rushing/php-*` home is reopened only when a third consumer appears.
4. **Usage is a named meter, not token cost** (D11). `targetMonthlyTokenCostUsd` became `targetMonthlyUsageCostUsd`,
   with `usageMeter` defaulting to `beam.synthetic.usage_meter` (`openai.tokens`). The old key is read for one release
   through `#[MapInputName]`, and a payload carrying both spellings is refused rather than letting the old one win.
5. **Replay validation lives in the DTO, not the generator** (D8). `prepareForPipeline()` refuses an unknown scenario
   by name, before the enum cast fails opaquely. The LLM generator and its prompt stay in the flagship; a generated
   blueprint must still pass `from()`.
6. **Two registries, one entry type each.** `BlueprintRegistry` (`beam.synthetic.blueprints`) and `PortfolioRegistry`
   (`beam.synthetic.portfolios`) are popcorn `#[IsRegistry]` roots filled lazily, on first resolve, from the host's
   `beam.synthetic.blueprint_paths` / `portfolio_paths` (`*.yaml`, `*.yml`, `*.json`). Duplicate ids are **rejected**,
   not superseded: the id seeds the curve and names the blueprint in a portfolio, so a silent override would replay
   correlated curves under one name. Unparseable, unreplayable and duplicate files fail loudly, naming the file.
7. **The package ships a generic Demo set** (three blueprints with fictional `.example` owners and the `demo-mixed-book`
   portfolio). A declared path list replaces it, so a host drops it by leaving it out. The flagship's five
   SecondParty blueprints stay in the flagship (flagship ADR-0157).

## Consequences

- Any host requiring this package can replay, materialize and project a book; the flagship adopted it and deleted
  `app/Synthetic` and tower's copies (exec-board 15).
- The pre-rename key `token_cost_usd` survives only as the golden fixture's frozen row format.
- Drop the `targetMonthlyTokenCostUsd` alias one release after the flagship's YAMLs moved (they moved in exec-board 15).
