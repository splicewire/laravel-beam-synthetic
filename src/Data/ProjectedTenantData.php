<?php

namespace Splicewire\Beam\Synthetic\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One synthetic tenant's curve run forward: who it is, what it is told from, and its month-by-month usage cost and
 * seats from the month it starts. Read-only: nothing behind it is a row.
 */
#[TypeScript]
class ProjectedTenantData extends BeamData
{
    /**
     * @param  list<ScenarioMonthData>  $projection
     */
    public function __construct(
        public string $tenant,
        public string $blueprint,
        public string $scenario,
        public string $plan,
        public string $usageMeter,
        public ?string $startsAt,
        #[DataCollectionOf(ScenarioMonthData::class)]
        public array $projection,
        public float $totalUsageCostUsd,
    ) {}
}
