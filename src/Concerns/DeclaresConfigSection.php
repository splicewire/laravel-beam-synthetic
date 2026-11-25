<?php

namespace Splicewire\Beam\Synthetic\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Synthetic\BeamSyntheticServiceProvider;
use Splicewire\Beam\Synthetic\Config\BeamSyntheticSection;

/**
 * One concern of {@see BeamSyntheticServiceProvider}: declares the `beam.synthetic` Config Section (ADR-0245)
 * with the registry, so the Config area and the section's JSON Schema describe this root's keys. The
 * declaration lands on first resolve of the registry (`callAfterResolving`), never at every boot.
 */
trait DeclaresConfigSection
{
    #[Chained('register', order: 120)]
    protected function declareConfigSection(): void
    {
        $this->callAfterResolving(ConfigSectionRegistry::class, static function (ConfigSectionRegistry $sections): void {
            $sections->section(
                BeamSyntheticSection::class,
                owner: 'splicewire/laravel-beam-synthetic',
                packageFile: __DIR__.'/../../config/beam/synthetic.php',
            );
        });
    }
}
