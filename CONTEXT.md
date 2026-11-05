# Beam Synthetic

Synthesize mode for Beam hosts. The terms below are the ones this package will own; each gains its
code in a later exec-board ticket.

## Language

**Synthetic tenant**:
A tenant row materialized from a blueprint and flagged synthetic on Beam's `Tenant`. It is real data
in the database, so it shows up in Live reads unless a host's reporting toggle excludes it.
_Avoid_: fake tenant, demo account

**Curve**:
A month-by-month shape (growth, usage, churn) produced by the neutral kernel in `Curve\`. A curve
knows nothing about tenants, plans or money.

**Blueprint**:
The declarative description of one synthetic party: its envelope, its months and its scenario. A
blueprint is data and can be replayed and validated; how one is generated (by hand, or by a host's
LLM prompt) is the host's business.
_Avoid_: fixture, seed

**Portfolio**:
A set of blueprints read together. The Scenario lens reads a portfolio in memory without touching the
database.

**Materializer**:
The generic writer that turns a blueprint into tenants, subscriptions and ledger rows through
beam-tenancy and beam-commerce types. Host-specific steps join it as extras.

**Projection**:
A forward forecast from a live baseline at an anchor month. Version 1 holds each party's last closed
period flat, adjusted by optional portfolio churn and expansion rates; it fits no curves.
