<?php

namespace Splicewire\Beam\Synthetic\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Synthetic\BeamSyntheticServiceProvider;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\Blueprints\DirectoryRegistrar;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
use Splicewire\Beam\Synthetic\Curve\CurveKernel;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;
use Splicewire\Beam\Synthetic\Data\SyntheticTenantBlueprintData;
use Splicewire\Beam\Synthetic\ScenarioExpander;

/**
 * One concern of {@see BeamSyntheticServiceProvider}: the curve kernel, the expander, and the
 * blueprint and portfolio registries.
 *
 * Both registries are SINGLETONS bound in the register phase, so a package or host registering a
 * blueprint from its own boot phase lands in the one instance every reader resolves. An unbound
 * registry would auto-resolve to a fresh, empty instance per call, and nothing would error.
 *
 * Each registry's {@see DirectoryRegistrar} is attached inside its singleton factory, so the config
 * is read and the files parsed on first resolve, not at every boot of a host that never synthesizes.
 */
trait WiresBlueprints
{
    #[Chained('register', order: 100)]
    protected function registerBlueprints(): void
    {
        $this->app->singleton(CurveKernel::class);
        $this->app->singleton(ScenarioExpander::class, fn ($app) => new ScenarioExpander($app->make(CurveKernel::class)));

        $this->app->singleton(BlueprintRegistry::class, function ($app) {
            $registry = new BlueprintRegistry;
            $registry->attach(new DirectoryRegistrar(
                paths: self::syntheticPaths($app, 'beam.synthetic.blueprint_paths'),
                configKey: 'beam.synthetic.blueprint_paths',
                hydrate: fn (array $payload): SyntheticTenantBlueprintData => SyntheticTenantBlueprintData::from($payload),
                key: fn (SyntheticTenantBlueprintData $blueprint): string => $blueprint->id,
            ));

            return $registry;
        });

        $this->app->singleton(PortfolioRegistry::class, function ($app) {
            $registry = new PortfolioRegistry;
            $registry->attach(new DirectoryRegistrar(
                paths: self::syntheticPaths($app, 'beam.synthetic.portfolio_paths'),
                configKey: 'beam.synthetic.portfolio_paths',
                hydrate: fn (array $payload): ScenarioPortfolioData => ScenarioPortfolioData::from($payload),
                key: fn (ScenarioPortfolioData $portfolio): string => $portfolio->id,
            ));

            return $registry;
        });
    }

    /**
     * The directories under `$key`, a relative one resolved against the host's base path.
     *
     * @return list<string>
     */
    private static function syntheticPaths($app, string $key): array
    {
        $paths = (array) $app['config']->get($key, []);

        return array_values(array_map(
            fn (string $path): string => str_starts_with($path, '/') ? $path : $app->basePath($path),
            array_filter($paths, 'is_string'),
        ));
    }
}
