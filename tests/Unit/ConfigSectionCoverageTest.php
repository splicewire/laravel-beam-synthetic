<?php

use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Config\ConfigSectionCoverage;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Splicewire\Beam\Synthetic\Config\BeamSyntheticSection;

it('registers the beam.synthetic config section from the service provider', function () {
    $registered = app(ConfigSectionRegistry::class)->get('beam.synthetic');

    expect($registered)->not->toBeNull()
        ->and($registered->section)->toBe(BeamSyntheticSection::class)
        ->and($registered->owner)->toBe('splicewire/laravel-beam-synthetic');
});

it('covers every beam.synthetic key and carries no draft copy', function () {
    expect(ConfigSectionCoverage::uncovered(BeamSyntheticSection::class, config('beam.synthetic')))->toBe([])
        ->and(ConfigSectionCoverage::drafts(BeamSyntheticSection::class))->toBe([]);
});

it('covers a host-supplied materializer map', function () {
    // materializers is a name => class-string map; a host registers extra steps.
    $values = array_merge(config('beam.synthetic'), [
        'materializers' => ['demo' => 'App\\Synthetic\\DemoMaterializer'],
    ]);

    expect(ConfigSectionCoverage::uncovered(BeamSyntheticSection::class, $values))->toBe([]);
});

it('validates the package default config against the section schema (the host-audit check)', function () {
    // The same check ConfigSectionCoverageAudit runs at boot: the package's own default config
    // must satisfy the generated section schema (AcceptanceGate -> opis). `materializers` ships as
    // null, which the nullable schema accepts; an empty-array host map would have failed type:object.
    $schema = app(ConfigSectionSchema::class)->for(BeamSyntheticSection::class);

    expect(app(AcceptanceGate::class)->accepts(config('beam.synthetic'), $schema))->toBeTrue();
});
