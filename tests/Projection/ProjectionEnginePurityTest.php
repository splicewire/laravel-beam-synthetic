<?php

/*
 * The projection engine names no framework or database type. This reads the `use` lines of every file in
 * `src/Projection/` (recursively) and allows only PHP's `InvalidArgumentException`, the engine's own namespace, and
 * one domain value: beam-commerce's `PartyPeriodRevenue`, the row shape the engine emits. That value is checked to be
 * import-free itself, so the allowance cannot smuggle a framework type in one hop.
 *
 * Inline fully qualified names and calls to the clock, the container, config or a random source would dodge a
 * `use`-line read, so those are checked too.
 */

use Splicewire\Beam\Commerce\Revenue\PartyPeriodRevenue;

const PROJECTION_DIR = __DIR__.'/../../src/Projection';
const PROJECTION_ALLOWED_IMPORTS = [
    'InvalidArgumentException',
    'Splicewire\\Beam\\Synthetic\\Projection\\',
    'Splicewire\\Beam\\Commerce\\Revenue\\PartyPeriodRevenue',
];

/** @return array<string, string> relative file => contents */
function projectionSources(): array
{
    $sources = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PROJECTION_DIR, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() === 'php') {
            $sources[substr($file->getPathname(), strlen(PROJECTION_DIR) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }

    expect($sources)->not->toBeEmpty('No engine sources read; the purity probe would pass by not looking.');
    ksort($sources);

    return $sources;
}

/** @return list<string> the imported names in `$source` */
function projectionImports(string $source): array
{
    preg_match_all('/^use\s+(?:function\s+|const\s+)?([^;]+);/m', $source, $matches);

    return array_map('trim', $matches[1]);
}

function projectionImportAllowed(string $import): bool
{
    foreach (PROJECTION_ALLOWED_IMPORTS as $allowed) {
        $prefix = str_ends_with($allowed, '\\');

        if ($prefix ? str_starts_with($import, $allowed) : $import === $allowed) {
            return true;
        }
    }

    return false;
}

/** @return list<string> inline framework names and impure calls in `$source` */
function projectionInlineViolations(string $source): array
{
    $code = (string) preg_replace(['#/\*.*?\*/|//[^\n]*#s', '/^namespace\s+[^;]+;/m'], '', $source);
    $violations = [];

    $framework = '/(?<!\w)\\\\?(?:Illuminate|Spatie|Carbon|Rushing|Schemastud|App|Stancl|Splicewire(?!\\\\Beam\\\\Synthetic\\\\Projection\\\\|\\\\Beam\\\\Commerce\\\\Revenue\\\\PartyPeriodRevenue\b))\\\\[\w\\\\]+/';

    if (preg_match_all($framework, $code, $m)) {
        array_push($violations, ...$m[0]);
    }

    if (preg_match_all('/(?<![\w>:$])(config|app|now|today|env|resolve|cache|DB|date|time|microtime|hrtime|rand|mt_rand|random_int|uniqid|getenv)\s*\(/', $code, $m)) {
        array_push($violations, ...array_map(fn (string $f): string => "calls {$f}()", $m[1]));
    }

    return $violations;
}

it('reads every engine file', function () {
    expect(array_keys(projectionSources()))->toBe([
        'PriceChange.php', 'ProjectionAssumptions.php', 'ProjectionBaseline.php', 'ProjectionEngine.php',
        'ProjectionMonth.php', 'RevenueProjection.php',
    ]);
});

it('imports nothing but PHP core, its own namespace and the PartyPeriodRevenue row', function () {
    $violations = [];

    foreach (projectionSources() as $file => $source) {
        foreach (projectionImports($source) as $import) {
            if (! projectionImportAllowed($import)) {
                $violations[] = "{$file}: use {$import}";
            }
        }
    }

    expect($violations)->toBe([]);
});

it('names no framework type inline and calls no clock, container, config or random source', function () {
    $violations = [];

    foreach (projectionSources() as $file => $source) {
        foreach (projectionInlineViolations($source) as $hit) {
            $violations[] = "{$file}: {$hit}";
        }
    }

    expect($violations)->toBe([]);
});

it('allows a row type that is itself import-free', function () {
    $source = (string) file_get_contents((new ReflectionClass(PartyPeriodRevenue::class))->getFileName());

    expect(projectionImports($source))->toBe([])
        ->and(projectionInlineViolations($source))->toBe([]);
});

it('would catch a framework, a database and a sibling import (negative control)', function () {
    expect(projectionImportAllowed('Illuminate\\Support\\Facades\\DB'))->toBeFalse()
        ->and(projectionImportAllowed('Illuminate\\Support\\Collection'))->toBeFalse()
        ->and(projectionImportAllowed('Carbon\\CarbonImmutable'))->toBeFalse()
        ->and(projectionImportAllowed('Splicewire\\Beam\\Commerce\\Revenue\\MeasuredRevenueLedger'))->toBeFalse()
        ->and(projectionImportAllowed('Splicewire\\Beam\\Commerce\\Subscription'))->toBeFalse()
        ->and(projectionImportAllowed('Splicewire\\Beam\\Synthetic\\Baseline\\MeasuredBaselineLoader'))->toBeFalse()
        ->and(projectionImportAllowed('Splicewire\\Beam\\Synthetic\\Projection\\ProjectionEngine'))->toBeTrue();

    expect(projectionImports("<?php\nuse Illuminate\\Support\\Facades\\DB;\nuse InvalidArgumentException;\n"))
        ->toBe(['Illuminate\\Support\\Facades\\DB', 'InvalidArgumentException']);

    expect(projectionInlineViolations('<?php $x = \\Illuminate\\Support\\Facades\\DB::table("t"); $y = now(); $z = $this->now();'))
        ->toBe(['\\Illuminate\\Support\\Facades\\DB', 'calls now()']);
});
