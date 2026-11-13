# Beam Synthetic

Synthesize mode for Beam hosts. The terms below are the ones this package owns. Curve, blueprint,
portfolio, scenario, roster, materializer, extra and projection have code now.

Stored portfolios have two identities: a server-managed central particle UUID and the person-chosen
scenario key in payload, which remains the board/lens id. **PROVISIONAL pending d62cb04e:** writes require
synthetic.portfolios.manage, while trusted registry reads retain viewer/CLI access. The dedicated model
and stub are withdrawn; see docs/stored-portfolio-migration.md for existing published-table hosts.

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

**Roster**:
Who a synthesize run is about and over which months: one blueprint read as one tenant under its own
id, or a portfolio read as `count` tenants per member (`{blueprint}-{k}`), each starting at its
offset. The materializer writes a roster's history (closed months before now); `synthetic:project`
reads it forward from an anchor. The tenant id seeds the curve.

**Materializer**:
The generic writer that turns a roster into synthetic tenants: the tenant (saved through the host's
provisioning path, flagged synthetic), its plan subscription, seats that follow the seat curve,
usage on the blueprint's meter and, on request, the closed periods' bills. It writes through
beam-tenancy and beam-commerce types only. Host-specific steps join it as extras.
_Avoid_: seeder

**Extra**:
A host's own materialization step (`MaterializesSyntheticExtras`, registered in
`beam.synthetic.materializers`). It runs on the central plane or, inside `$tenant->run()`, on the
tenant plane.

**Projection**:
A forward forecast of revenue from a baseline at an anchor month. Version 1 holds each party's last
closed month flat and moves it by monthly assumptions: churn and expansion (a portfolio's rates map
onto these), growth (new paying parties) and price changes. It fits no curves. The engine is pure,
and its rows are the ledger's `PartyPeriodRevenue`, so the same `RevenueMovements` aggregates real
and projected revenue. Not to be confused with `synthetic:project`, which runs a roster's usage and
seat curves forward.
_Avoid_: forecast model, simulation

**Baseline**:
The projection's starting point: every party's revenue in one closed month, the **anchor**. The
baseline loader reads it from the live ledger; that read is the only database access in a
projection.
