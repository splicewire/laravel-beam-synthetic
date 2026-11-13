<?php

namespace Splicewire\Beam\Synthetic\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;

/** The chosen scenario key is payload; it never replaces the particle envelope id. */
#[Title('Stored portfolio details')]
class StoredPortfolioInputData extends Data
{
    public function __construct(
        public string $scenarioKey,
        public ScenarioPortfolioData $portfolio,
    ) {}
}
