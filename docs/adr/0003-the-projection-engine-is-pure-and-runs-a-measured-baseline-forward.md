# ADR-0003 — The projection engine is pure, and runs a measured baseline forward

**Status:** accepted
**Date:** 2026-11-05
**Repo:** `splicewire/laravel-beam-synthetic`
**Wayfinding:** `.scratch/splicewire/splicewire-ecosystem/exec-board/` (PLAN.md D13; tickets 18, 19).
**Supersedes in part:** `splicewire/splicewire-app docs/adr/0026-synthetic-tenants-via-scenario-replay.md` (its
"financial projection engine … enabled but explicitly not built" is now built, here).
**Relates to:** `splicewire/laravel-beam-commerce docs/adr/0008-mrr-is-contracted-recurring-revenue-derived-by-one-aggregator.md`
(the rows it produces and the aggregator that reads them); `splicewire/laravel-beam-exec docs/adr/0004-a-lens-chooses-the-board-source-and-a-synthesized-or-projected-figure-is-always-watermarked.md`
(the Projection lens that reads it).

## Context

A board wants "where does the book land in six months". Flagship ADR-0026 kept the expander pure precisely so a
forward-running, read-only projection could exist one day. The risk is a second revenue model: if a projection
computed MRR, churn or NRR its own way, a projected number and a measured one could disagree about what the words
mean.

## Decision

1. **The engine is pure and small.** `src/Projection/` holds `ProjectionEngine`, `ProjectionBaseline`,
   `ProjectionAssumptions`, `PriceChange`, `RevenueProjection` and `ProjectionMonth` (month arithmetic on `YYYY-MM`,
   no Carbon). Those files import only their own namespace, `InvalidArgumentException` and commerce's
   `PartyPeriodRevenue`, a rule a test enforces on their `use` lines. The loader that reads a live baseline is
   `Baseline\MeasuredBaselineLoader`, outside that directory.
2. **Its output is commerce's input.** `RevenueProjection::periods()` (the anchor, then the projected months) and
   `rows()` (`PartyPeriodRevenue`, baseline then projected) go to commerce's `RevenueMovements::from()` unchanged, so
   a projected MRR, churn or NRR is computed by the same aggregator as a measured one. `billedUsd` and `paidUsd` are
   always null.
3. **The baseline is held flat (D13).** Each party's anchor month (the last closed month) is carried forward; there
   is no curve-fitting in v1. `ProjectionBaseline::fromRows()` sums duplicate rows, refuses a row outside the anchor
   and a party on two plans, and sorts by party id, so input order never changes the output.
4. **Assumptions are monthly rates.** Churn and growth count **whole parties**: each rate × the paying parties at the
   end of the month before is added to a running remainder, and whole parties are taken out of it, in a fixed order
   (logo churn is exact to the rate). Expansion compounds recurring revenue. A negative growth rate floors at zero
   new parties (losing parties is churn's job). Revenue never drops below zero, and a party at zero reads as logo
   churn, matching commerce's "a move to a free plan is churn". A price change at or before the anchor is refused
   (the anchor's prices already carry it); the exec lens skips a configured one instead, so the board survives the
   anchor moving past it.
5. **A portfolio may supply the rates.** `ScenarioPortfolioData`'s optional `churnRate` and `expansionRate` win where
   they are stated; otherwise the host's configured rates apply.
6. **The golden is an inline literal worked by hand plus its JSON's sha256.** There is no fixture file, so there is no
   regeneration command, and the test never writes.

Rejected: scaling every party by `(1 − churn)`. The aggregator would read it as contraction, and logo churn would
always be zero.

## Consequences

- Real, synthetic and projected revenue cannot drift apart in meaning: one pricer (commerce), one aggregator
  (commerce), one engine (here).
- A party with two plans in its anchor month stops a projection, loudly. The flagship's data should be checked for
  one before its board shows Projection.
- Projecting tenants or plan mix from projected parties is nominated, not built.
