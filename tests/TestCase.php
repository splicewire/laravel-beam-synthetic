<?php

namespace Splicewire\Beam\Synthetic\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\Synthetic\BeamSyntheticServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * This package's provider, plus each provider a concern needs, joining with that concern as the
     * sibling harnesses do. laravel-data joined with the blueprint DTO family, whose `from()` reads its
     * `data` config.
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            BeamSyntheticServiceProvider::class,
        ];
    }
}
