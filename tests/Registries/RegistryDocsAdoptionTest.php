<?php

use Rushing\Popcorn\Registries\Docs\RegistryDocsCheck;

/*
 * This package adopts the package-tier registry docs check over the whole of `src/`: every declared
 * root is covered under `docs/registries/`, and every page's generated facts block is byte-identical
 * to a fresh render of its `#[IsRegistry]` declaration.
 *
 * The coverage count is asserted too, so an inactive check (no `docs/registries/` directory) or a
 * scan that finds nothing cannot pass by reporting no failures.
 */
it('documents every declared registry root with its facts in step', function () {
    $report = RegistryDocsCheck::forPackage(dirname(__DIR__, 2))->run();

    expect($report->failures())->toBe([], $report->summary())
        ->and(array_keys($report->coverage))->toEqualCanonicalizing(['beam.synthetic.blueprints', 'beam.synthetic.portfolios']);
});
