# Beam Synthetic

Synthesize mode for Beam hosts. The terms below are the ones this package owns. Curve, blueprint,
portfolio and scenario have code now; the materializer and projection follow.

## Language

**Synthetic tenant**:
A tenant row materialized from a blueprint and flagged synthetic on Beam's `Tenant`. It is real data
in the database, so it shows up in Live reads unless a host's reporting toggle excludes it.
_Avoid_: fake tenant, demo account

**Curve**:
A period-by-period pair of series (a scalar and an integer) produced by the neutral kernel in
`Curve\` from a growth shape, a seed and an envelope. A curve knows nothing about tenants, plans,
months or money; `ScenarioExpander` reads its scalar as usage cost and its integer as seats.

**Growth shape**:
The curve's form: steady ramp, seasonal, or dip-then-recover. A **scenario** is the billing name for
one (`churned-then-resumed` is the dip-then-recover shape).

**Cutoff**:
The last live period of a curve; every period after it is zero. A blueprint's `endedAtMonth` (hard
churn) becomes the cutoff.
_Avoid_: churn, when you mean the cutoff (churned-then-resumed is a shape, not a cutoff)

**Blueprint**:
The declarative description of one synthetic party: its identity, plan, scenario, usage target and
seat ramp, plus an optional hard-churn month and usage meter. A
blueprint is data and can be replayed and validated; how one is generated (by hand, or by a host's
LLM prompt) is the host's business.
_Avoid_: fixture, seed

**Portfolio**:
A set of blueprints read together, each with a count and a start offset, plus optional monthly churn
and expansion rates. The Scenario lens reads a portfolio in memory without touching the
database.

**Materializer**:
The generic writer that turns a blueprint into tenants, subscriptions and ledger rows through
beam-tenancy and beam-commerce types. Host-specific steps join it as extras.

**Projection**:
A forward forecast from a live baseline at an anchor month. Version 1 holds each party's last closed
period flat, adjusted by optional portfolio churn and expansion rates; it fits no curves.
