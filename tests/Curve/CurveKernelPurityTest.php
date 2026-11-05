<?php

/*
 * The curve kernel names no framework or domain type. This reads the `use` lines of every file in
 * `src/Curve/` and allows only PHP's own `Random\` engine and the kernel's own namespace.
 *
 * Fully qualified names written inline (`\Illuminate\...`) would dodge a `use`-line read, so those are
 * checked too.
 */

const CURVE_DIR = __DIR__.'/../../src/Curve';
const CURVE_ALLOWED_IMPORTS = ['Random\\', 'Splicewire\\Beam\\Synthetic\\Curve\\', 'InvalidArgumentException'];

/** @return array<string, string> file => contents */
function curveSources(): array
{
    $files = glob(CURVE_DIR.'/*.php') ?: [];

    expect($files)->not->toBeEmpty('No kernel sources read; the purity probe would pass by not looking.');

    $sources = [];
    foreach ($files as $file) {
        $sources[basename($file)] = (string) file_get_contents($file);
    }

    return $sources;
}

/** @return list<string> the imported names in `$source` */
function curveImports(string $source): array
{
    preg_match_all('/^use\s+(?:function\s+|const\s+)?([^;]+);/m', $source, $matches);

    return array_map('trim', $matches[1]);
}

function curveImportAllowed(string $import): bool
{
    foreach (CURVE_ALLOWED_IMPORTS as $allowed) {
        if ($import === rtrim($allowed, '\\') || str_starts_with($import, $allowed)) {
            return true;
        }
    }

    return false;
}

it('reads all five kernel files', function () {
    expect(array_keys(curveSources()))->toEqualCanonicalizing([
        'CurveEnvelope.php', 'CurveKernel.php', 'CurvePeriod.php', 'CurvePoint.php', 'GrowthShape.php',
    ]);
});

it('imports nothing but PHP core and its own namespace', function () {
    $violations = [];

    foreach (curveSources() as $file => $source) {
        foreach (curveImports($source) as $import) {
            if (! curveImportAllowed($import)) {
                $violations[] = "{$file}: use {$import}";
            }
        }
    }

    expect($violations)->toBe([]);
});

it('names no framework or domain type inline either', function () {
    $violations = [];

    foreach (curveSources() as $file => $source) {
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

        if (preg_match_all('/\\\\?(Illuminate|Spatie|Carbon|Splicewire\\\\(?!Beam\\\\Synthetic\\\\Curve)|Rushing|Schemastud|App)\\\\\w+/', (string) $code, $m)) {
            foreach ($m[0] as $hit) {
                $violations[] = "{$file}: {$hit}";
            }
        }

        if (preg_match('/\b(config|app|now|env|resolve)\s*\(/', (string) $code, $call)) {
            $violations[] = "{$file}: calls {$call[1]}()";
        }
    }

    expect($violations)->toBe([]);
});

it('would catch a framework import and a domain import (negative control)', function () {
    expect(curveImportAllowed('Illuminate\\Support\\Collection'))->toBeFalse()
        ->and(curveImportAllowed('Carbon\\CarbonImmutable'))->toBeFalse()
        ->and(curveImportAllowed('Splicewire\\Beam\\Synthetic\\Enums\\SyntheticScenario'))->toBeFalse()
        ->and(curveImportAllowed('Splicewire\\Beam\\Synthetic\\Data\\ScenarioEnvelopeData'))->toBeFalse()
        ->and(curveImportAllowed('Random\\Randomizer'))->toBeTrue();

    expect(curveImports("<?php\nuse Illuminate\\Support\\Arr;\nuse Random\\Randomizer;\n"))
        ->toBe(['Illuminate\\Support\\Arr', 'Random\\Randomizer']);
});
