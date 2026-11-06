<?php

namespace Splicewire\Beam\Synthetic;

use Rushing\Popcorn\Concerns\ChainsTraitMethods;
use Rushing\Popcorn\Contracts\ChainsTraitMethods as ChainsTraitMethodsContract;
use Rushing\Popcorn\Registries\ScopesRegistrant;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Synthetic\Concerns\WiresBlueprints;
use Splicewire\Beam\Synthetic\Concerns\WiresMaterializer;
use Splicewire\Beam\Synthetic\Console\CaptureCurvesCommand;
use Splicewire\Beam\Synthetic\Console\MaterializeCommand;
use Splicewire\Beam\Synthetic\Console\ProjectCommand;

/**
 * Synthesize mode for Beam hosts.
 *
 * Each concern this package wires arrives as a `Wires*` trait whose methods carry
 * `#[Chained('register')]` or `#[Chained('boot')]` with a declared `order:`, the beam-ux shape. Adding
 * a concern is `use`-ing its trait; this class never hand-lists the steps. `ScopesRegistrant` brackets
 * both phases, so every registry write in them that names no `$by` records this package.
 */
class BeamSyntheticServiceProvider extends PackageServiceProvider implements ChainsTraitMethodsContract
{
    use ChainsTraitMethods;
    use ScopesRegistrant;
    use WiresBlueprints;
    use WiresMaterializer;

    protected function registrant(): string
    {
        return 'splicewire/laravel-beam-synthetic';
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam-synthetic')
            ->hasConfigFile(['beam/synthetic'])
            // Publish-only, the estate's stub-migration convention (beam-commerce's docblock): the `.stub` is never
            // loaded in place; `vendor:publish --tag=beam-synthetic-migrations` or `splicewire:beam:install` stamps it
            // into the host, which runs it on its central connection.
            ->hasMigrations(['create_beam_synthetic_portfolios_table'])
            ->hasCommands([
                MaterializeCommand::class,
                ProjectCommand::class,
                CaptureCurvesCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->chainTraitMethods('register');
    }

    public function packageBooted(): void
    {
        $this->chainTraitMethods('boot');
    }
}
