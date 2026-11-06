<?php

// splicewire/laravel-beam-synthetic, merged under `beam.synthetic.*`.
//
// Each key lands with the code that reads it, in the same commit, so no key exists without a reader.

use Splicewire\Beam\Synthetic\Blueprints\DemoBlueprints;

return [

    // The meter a blueprint's usage cost is billed against when the blueprint names none
    // (SyntheticTenantBlueprintData::$usageMeter).
    'usage_meter' => 'openai.tokens',

    // Directories of blueprint files (*.yaml, *.yml, *.json; one blueprint per file), read into
    // BlueprintRegistry on first resolve. A relative path resolves against the host's base path. The
    // default is this package's Demo blueprints; a host that declares its own list replaces it, so
    // list DemoBlueprints::blueprints() too to keep them.
    'blueprint_paths' => [
        DemoBlueprints::blueprints(),
    ],

    // Directories of portfolio files, read into PortfolioRegistry the same way. The default is this
    // package's Demo portfolio.
    'portfolio_paths' => [
        DemoBlueprints::portfolios(),
    ],

    // Whether PortfolioRegistry also reads the portfolios a host saved at runtime (StoredPortfolios: the central
    // `beam_synthetic_portfolios` table, published by this package's migration and written by the exec board's
    // scenario builder). A declared portfolio (a file above) always wins its id. false reads files only and never
    // queries; true on a host without the table reads no stored portfolios rather than failing.
    'portfolio_store' => true,

    // The host's own materialization steps, `name => class` (each a MaterializesSyntheticExtras), read into
    // SyntheticExtrasRegistry (`beam.synthetic.materializers`). They run in this order, after the generic writes.
    'materializers' => [],

    // How many metered units a dollar of synthetic usage stands for, when the host declares no unit cost for the
    // meter (MeterRegistry). The default is the flagship's ratio for `openai.tokens`.
    'usage_units_per_usd' => 1000,

    // Whether the materializer provisions a synthetic tenant's storage itself (stancl's CreateDatabase, then a
    // tenants:migrate scoped to that tenant). It does so only where the host declares no provisioning pipeline; a
    // host that does owns its tenants' storage.
    'provision_storage' => true,

];
