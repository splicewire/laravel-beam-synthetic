# ADR-0004 — Stored portfolios are read beside the files, and a declared id always wins

**Status:** accepted (recommended option, exec-board 24 hands-free; owner may overrule)
**Date:** 2026-11-06
**Repo:** `splicewire/laravel-beam-synthetic`
**Wayfinding:** `.scratch/splicewire/splicewire-ecosystem/exec-board/tickets/24-scenario-builder.md`. Owner question,
2026-11-06: "is there a way to create a scenario (like a modular scenario builder) or indicate where/how to seed
synthetic portfolio?"
**Amends:** [ADR-0001](0001-the-curve-kernel-is-neutral-and-blueprints-are-registered-from-host-directories.md)
decision 6 (portfolios came only from host directories).
**Relates to:** `docs/registries/beam.synthetic.portfolios.mdx`; `splicewire/laravel-beam-exec` README "Scenarios".

## Context

A portfolio could only come from a file under `beam.synthetic.portfolio_paths`, so showing the board a new scenario
meant a code change and a deploy. The exec board wanted a builder: compose a portfolio from the host's blueprints,
preview it, and keep it. `PortfolioRegistry` is a popcorn registry filled once, lazily, into a singleton, which a
long-lived worker keeps between requests, so runtime rows cannot simply be registered into it.

## Decision

1. **A central table, `beam_synthetic_portfolios`, holds portfolios saved at runtime**, one row per portfolio (`id`,
   `label`, the whole `ScenarioPortfolioData` as `definition`, who saved it). It ships as a publish-only migration stub
   (`beam-synthetic-migrations`), registered into beam's install manifest like beam-commerce's, and its model is
   `'central'`-pinned (`@central-floor registry-runtime`).
2. **`PortfolioRegistry` reads the store; it does not absorb it.** Its own entries stay the declared ones (files and
   code). `get()` answers a declared id first and asks the store only on a miss; `all()` is the declared ones, then
   the stored ones. `StoredPortfolios` is bound scoped and memoizes its rows per request, so a singleton registry
   never holds stale rows and a scenario saved by one request is read by the next.
3. **A declared id always wins, and saving one is refused.** A file is reviewed with the host's code; a stored row is
   runtime data. Refusing at save keeps the common case honest; reading the file when a stored row is shadowed
   (a file added later under a stored id) keeps the rare case safe, and `shadowed()` names the row. Rejected:
   letting a stored row supersede a file (a board member could silently replace a reviewed portfolio), and failing
   the registry on collision (a later file would break every reader until someone deleted a row).
4. **A stored row is validated again whenever it is read** (`ScenarioPortfolioData::from()` plus
   `BlueprintRegistry::missingFrom()`); one that no longer validates is left out and reported by `invalid()`, never
   thrown at a reader. Ids are kebab-case, because the id becomes the exec lens value `scenario:<id>`.
5. **Every save and delete is written to the central activity trail** (`log_name` `synthetic-portfolios`, the
   portfolio before and after, the causer), in the store, so any writer is logged, not only the exec builder.
6. **Cost is bounded and explicit.** A declared-id lookup, `declared()`, `declares()` and `firstId()` (when a file
   exists) never query. The store costs two queries per request (table check, rows). A host with no `central`
   connection or no table reads no stored portfolios; `beam.synthetic.portfolio_store` false turns the store off.

## Consequences

- A stored portfolio is a `synthetic:project --portfolio`, a `synthetic:materialize --portfolio` and an exec Scenario
  lens with no second code path.
- `synthetic:project` stays zero-query for declared ids; naming a stored id reads the store (two queries) before the
  projection, which itself still reads nothing.
- The exec board's scenario builder (exec-board 24) is the first writer; `exec.scenario.manage` gates it there.


## Owner-review 18 amendment — PROVISIONAL pending root d62cb04e

Decision 1's dedicated model/table and publishable stub are withdrawn. StoredPortfolioData declares
CentralParticleBacking with explicit input Data and write ability synthetic.portfolios.manage.
The particle UUID is server-managed; scenarioKey in payload remains ScenarioPortfolioData.id in
board/lens contracts. Declared keys still refuse saves and shadow old stored rows. Reads revalidate.

Writes use the canonical backing pipeline; deletion uses its scoped lookup plus explicit ability,
as the existing owned-resource delete seam does. Exec supplies its existing two-entitlement default,
without a synthetic-to-exec import. Trusted internal reads are scoped to this schema, local tier and
central-host ownership and preserve viewer/CLI access. No generic CRUD route is mounted.
This policy seam is PROVISIONAL per coord04:29/04:36, pending root d62cb04e; nothing lands without root.

Save/delete and central activity audit are transactional, with a cache lock per connection/key.
Multiple workers require a shared lock-capable cache. Audit subjects identify the actual
beam_particle UUID and preserve scenario_key in properties. Legacy historical saver/timestamp facts
remain in the lossless export/move receipt, never relabeled as the importing actor.
Commerce-starter and market-starter published the retired stub; follow docs/stored-portfolio-migration.md.
No host migration or live data is changed by this candidate.
