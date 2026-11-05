<?php

namespace Splicewire\Beam\Synthetic\Data;

use Carbon\CarbonImmutable;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Synthetic\Curve\CurveEnvelope;
use Splicewire\Beam\Synthetic\Enums\SyntheticScenario;
use Splicewire\Beam\Synthetic\ScenarioExpander;

/**
 * The billing reading of one synthetic tenant's curve: the window's bounds, the scenario, the usage
 * cost the curve targets and the seat ramp. {@see ScenarioExpander} turns it
 * into a neutral {@see CurveEnvelope}.
 *
 * An internal parameter object, not a wire contract, so it is not TypeScript-exported. It carries no
 * plan: the curve never reads one, and the materializer takes the plan from the blueprint.
 */
class ScenarioEnvelopeData extends BeamData
{
    public function __construct(
        public CarbonImmutable $startedAt,
        public ?CarbonImmutable $endedAt,
        public SyntheticScenario $scenario,
        public float $targetMonthlyUsageCostUsd,
        public int $startingSeats,
        public int $endingSeats,
    ) {}
}
