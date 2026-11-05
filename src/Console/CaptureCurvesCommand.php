<?php

namespace Splicewire\Beam\Synthetic\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Synthetic\Golden\GoldenCurveCases;
use Splicewire\Beam\Synthetic\ScenarioExpander;

/**
 * Regenerate the committed golden curves: the REGENERATION half of the curve drift guard, whose verification half is
 * `tests/Scenario/ScenarioCurveGoldenTest.php`.
 *
 * Regeneration is a command, verification is a test, and the test never writes. A test that can rewrite its own
 * fixture (a `UPDATE_X=1` mode) can be made incapable of failing, by a stale export or a copied invocation. This
 * command writes only when asked to, and the diff it leaves is the record of a deliberate curve change.
 *
 * It renders through {@see GoldenCurveCases}, the same cases, path and normaliser the test reads, so the two cannot
 * describe different inputs.
 *
 * ```
 * vendor/bin/testbench synthetic:capture-curves            # print, and review
 * vendor/bin/testbench synthetic:capture-curves --write    # overwrite the committed fixture
 * ```
 *
 * `--path` writes somewhere else instead, so the regeneration can be diffed against the committed bytes without
 * touching them.
 */
class CaptureCurvesCommand extends Command
{
    protected $signature = 'synthetic:capture-curves
        {--write : Write the curves instead of printing them}
        {--path= : Where --write writes (default: the committed fixture)}';

    protected $description = 'Regenerate the golden synthetic curves (the regeneration half of the curve drift guard)';

    public function handle(ScenarioExpander $expander): int
    {
        $json = self::render($expander);

        if (! $this->option('write')) {
            $this->output->write($json);
            $this->newLine();
            $this->comment('Printed only. Re-run with --write to overwrite the committed fixture, then review the diff:');
            $this->comment('that diff is the record of a deliberate curve change.');

            return self::SUCCESS;
        }

        $path = (string) ($this->option('path') ?: GoldenCurveCases::path());

        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            $this->error("Cannot create the directory for {$path}.");

            return self::FAILURE;
        }

        $existed = is_file($path);

        if (file_put_contents($path, $json) === false) {
            $this->error("Could not write {$path}.");

            return self::FAILURE;
        }

        $this->info(($existed ? 'Overwrote ' : 'Created ').$path);
        $this->comment('Review the diff before committing. An unreviewed diff here rewrites what every synthetic tenant');
        $this->comment('is recorded as having used.');

        return self::SUCCESS;
    }

    /** The fixture's exact bytes for the current curves. */
    public static function render(ScenarioExpander $expander): string
    {
        return json_encode(GoldenCurveCases::expandAll($expander), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }
}
