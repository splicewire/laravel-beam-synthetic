<?php

namespace Splicewire\Beam\Synthetic\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One month of a synthetic tenant's expanded curve: the month (`YYYY-MM`), the usage cost billed
 * against the blueprint's usage meter, and the seat count.
 */
#[TypeScript]
class ScenarioMonthData extends BeamData
{
    public function __construct(
        public string $month,
        public float $usageCostUsd,
        public int $seatCount,
    ) {}
}
