<?php

namespace Splicewire\Beam\Synthetic;

use Rushing\Popcorn\Concerns\ChainsTraitMethods;
use Rushing\Popcorn\Contracts\ChainsTraitMethods as ChainsTraitMethodsContract;
use Rushing\Popcorn\Registries\ScopesRegistrant;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Synthetic\Concerns\WiresBlueprints;

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

    protected function registrant(): string
    {
        return 'splicewire/laravel-beam-synthetic';
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam-synthetic')
            ->hasConfigFile(['beam/synthetic']);
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
