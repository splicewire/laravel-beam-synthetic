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

];
