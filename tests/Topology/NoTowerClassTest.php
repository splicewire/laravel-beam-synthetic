<?php

/*
 * The manifest fences (OwnTopologyDeclarationTest) stop a composer require on tower. This is the
 * other half: no shipped file names a tower, satellite or host-app class, which would autoload only in
 * a host that happens to have tower installed and fatal everywhere else.
 *
 * Scope: every `.php` file under `src/` and `config/`. Unit: a `Splicewire\Tower\`,
 * `Splicewire\Satellite\` or `App\` name in code (comments are stripped first, so prose about the
 * flagship does not count).
 */

const NO_TOWER_PATTERN = '/\\\\?\b(Splicewire\\\\Tower|Splicewire\\\\Satellite|App)\\\\[A-Z]\w*/';

/** @return array<string, string> relative path => code with comments stripped */
function shippedCode(): array
{
    $root = dirname(__DIR__, 2);
    $code = [];

    foreach (['src', 'config'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $tokens = PhpToken::tokenize((string) file_get_contents($file->getPathname()));
            $code[substr($file->getPathname(), strlen($root) + 1)] = implode('', array_map(
                fn (PhpToken $t): string => $t->is([T_COMMENT, T_DOC_COMMENT]) ? '' : $t->text,
                $tokens,
            ));
        }
    }

    return $code;
}

it('reads the shipped sources it claims to', function () {
    expect(count(shippedCode()))->toBeGreaterThanOrEqual(15)
        ->and(array_keys(shippedCode()))->toContain('src/Curve/CurveKernel.php', 'src/Blueprints/BlueprintRegistry.php', 'config/beam/synthetic.php');
});

it('names no tower, satellite or host-app class in code', function () {
    $violations = [];

    foreach (shippedCode() as $file => $code) {
        if (preg_match_all(NO_TOWER_PATTERN, $code, $m)) {
            foreach ($m[0] as $hit) {
                $violations[] = "{$file}: {$hit}";
            }
        }
    }

    expect($violations)->toBe([]);
});

it('would catch a tower import (negative control)', function () {
    expect(preg_match(NO_TOWER_PATTERN, 'use Splicewire\\Tower\\Data\\Synthetic\\ScenarioMonthData;'))->toBe(1)
        ->and(preg_match(NO_TOWER_PATTERN, 'new \\App\\Synthetic\\ScenarioExpander;'))->toBe(1)
        ->and(preg_match(NO_TOWER_PATTERN, 'use Splicewire\\Beam\\Synthetic\\ScenarioExpander;'))->toBe(0);
});
