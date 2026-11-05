<?php

/*
 * This package's own `extra.package-topology`, checked against its own runtime `require`.
 *
 * Why not `AssertsDeclaredTopology` here: `DeclaredContractSource` reads only VENDORED manifests, and a
 * package does not vendor itself, so in this repo that assertion runs over zero of this package's rules
 * and passes by not looking (measured at splicewire/laravel-beam-market; see
 * splicewire/laravel-beam-dev's `OwnTopologyDeclarationTest`). The graph-level check, including
 * `neverReaches`, runs where this package IS vendored: a host's `DeclaredTopologyTest`.
 *
 * `require-dev` is out of scope, matching the tool: its graph is runtime `require` only.
 */

const SYNTHETIC_FORBIDDEN = ['splicewire/tower', 'splicewire/laravel-satellite', 'splicewire/laravel-tower-*'];
const SYNTHETIC_REQUIRED = ['splicewire/laravel-beam', 'splicewire/laravel-beam-tenancy', 'splicewire/laravel-beam-commerce'];

function syntheticManifest(): array
{
    $path = __DIR__.'/../../composer.json';
    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded)->toBeArray("Could not read {$path}");

    return $decoded;
}

function syntheticTopology(): array
{
    $topology = syntheticManifest()['extra']['package-topology'] ?? null;

    // A missing declaration must FAIL. "No rules" and "no violations" are otherwise the same green.
    expect($topology)->toBeArray('extra.package-topology is absent from composer.json');

    return $topology;
}

function syntheticMatchesAny(string $package, array $globs): bool
{
    foreach ($globs as $glob) {
        if (fnmatch($glob, $package)) {
            return true;
        }
    }

    return false;
}

it('declares the fences, pinned so narrowing one is a failing test rather than a quiet edit', function () {
    $topology = syntheticTopology();

    expect($topology['mustNotRequire'] ?? null)->toBe(SYNTHETIC_FORBIDDEN)
        ->and($topology['mustRequire'] ?? null)->toBe(SYNTHETIC_REQUIRED)
        ->and($topology['neverReaches'] ?? null)->toBe(['splicewire/laravel-beam-exec'])
        ->and($topology['because'] ?? '')->not->toBe('');
});

it('requires every package it declares it must require', function () {
    $requires = array_keys(syntheticManifest()['require'] ?? []);

    expect(array_values(array_diff(syntheticTopology()['mustRequire'], $requires)))->toBe([]);
});

it('requires nothing its own mustNotRequire forbids, and never requires exec', function () {
    $requires = array_keys(syntheticManifest()['require'] ?? []);

    expect($requires)->not->toBeEmpty('No runtime requires read; the manifest probe is broken.');

    $violations = array_values(array_filter(
        $requires,
        fn (string $p): bool => syntheticMatchesAny($p, syntheticTopology()['mustNotRequire']),
    ));

    expect($violations)->toBe([])
        ->and($requires)->not->toContain('splicewire/laravel-beam-exec');
});

it('would catch a forbidden require, and does not forbid the legal ones', function () {
    $globs = syntheticTopology()['mustNotRequire'];

    foreach (['splicewire/tower', 'splicewire/laravel-satellite', 'splicewire/laravel-tower-market'] as $planted) {
        expect(syntheticMatchesAny($planted, $globs))->toBeTrue("A require on {$planted} would NOT be caught.");
    }

    foreach ([...SYNTHETIC_REQUIRED, 'rushing/php-popcorn', 'spatie/laravel-package-tools'] as $legal) {
        expect(syntheticMatchesAny($legal, $globs))->toBeFalse("The fences wrongly forbid {$legal}.");
    }
});
