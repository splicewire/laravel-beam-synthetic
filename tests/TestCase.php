<?php

namespace Splicewire\Beam\Synthetic\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\Synthetic\BeamSyntheticServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * This package's provider, plus each provider a concern needs, joining with that concern as the
     * sibling harnesses do. laravel-data joined with the blueprint DTO family, whose `from()` reads its
     * `data` config. laravel-data-schemas joined with the config-section coverage test: it binds the
     * DataSchemas `Generator` interface ConfigSectionSchema depends on, resolved when that test validates
     * the package defaults against the generated schema (the same check the host audit runs). A real host
     * auto-discovers it; the package harness must name it.
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            LaravelDataSchemasServiceProvider::class,
            BeamSyntheticServiceProvider::class,
        ];
    }
}
