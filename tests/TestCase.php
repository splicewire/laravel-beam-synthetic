<?php

namespace Splicewire\Beam\Synthetic\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Splicewire\Beam\Synthetic\BeamSyntheticServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Only this package's provider. The scaffold wires nothing into beam, tenancy or commerce yet; each
     * provider a later concern needs joins this list with that concern, as the sibling harnesses do.
     */
    protected function getPackageProviders($app): array
    {
        return [
            BeamSyntheticServiceProvider::class,
        ];
    }
}
