<?php

namespace Splicewire\Beam\Synthetic\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Rushing\Popcorn\Registries\Registrars\ConfigRegistrar;
use Splicewire\Beam\Synthetic\BeamSyntheticServiceProvider;
use Splicewire\Beam\Synthetic\Extras\SyntheticExtrasRegistry;

/**
 * One concern of {@see BeamSyntheticServiceProvider}: the host-extras registry the materializer runs.
 *
 * The registry is a SINGLETON bound in the register phase, so an extra a host registers from its own provider lands
 * in the instance the materializer resolves. Its config entries (`beam.synthetic.materializers`, `name => class`)
 * arrive through a {@see ConfigRegistrar} attached in the factory, so they are read on first resolve.
 */
trait WiresMaterializer
{
    #[Chained('register', order: 110)]
    protected function registerMaterializer(): void
    {
        $this->app->singleton(SyntheticExtrasRegistry::class, function ($app) {
            $registry = new SyntheticExtrasRegistry($app);
            $registry->attach(new ConfigRegistrar(
                (array) $app['config']->get('beam.synthetic.materializers', []),
                'beam.synthetic.materializers',
            ));

            return $registry;
        });
    }
}
