<?php

namespace Splicewire\Beam\Synthetic\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Particle\Backing\CentralParticleBacking;
use Splicewire\Beam\Particle\Backing\SharedParticleAccess;

/**
 * Central stored scenarios. No public generic CRUD is mounted.
 * PROVISIONAL policy seam (owner-review 18, pending d62cb04e): transport reads and writes
 * require this ability; trusted internal PortfolioRegistry reads retain viewer/CLI behavior.
 */
#[ParticleResource(
    key: 'synthetic-portfolios',
    backing: CentralParticleBacking::class,
    input: StoredPortfolioInputData::class,
    policy: self::WRITE_ABILITY,
    readPolicy: SharedParticleAccess::class,
    frame: false,
    readOnly: true,
    editable: false,
    deletable: false,
)]
#[Title('Stored portfolio')]
class StoredPortfolioData extends Data
{
    public const WRITE_ABILITY = 'synthetic.portfolios.manage';

    public function __construct(
        public string $id,
        public string $scenarioKey,
        public ScenarioPortfolioData $portfolio,
    ) {}
}
