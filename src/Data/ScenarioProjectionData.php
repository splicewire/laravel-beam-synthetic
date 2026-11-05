<?php

namespace Splicewire\Beam\Synthetic\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * A blueprint or a portfolio run forward from an anchor month, read-only: each tenant's projection, and the
 * month-by-month totals across them (usage cost and seats summed; a tenant that has not started yet adds nothing).
 * `synthetic:project --json` emits it. `readOnly` is always true: a projection writes no rows and reads none.
 */
#[TypeScript]
class ScenarioProjectionData extends BeamData
{
    /**
     * @param  list<ProjectedTenantData>  $tenants
     * @param  list<ScenarioMonthData>  $totals
     */
    public function __construct(
        public ?string $blueprint,
        public ?string $portfolio,
        public string $from,
        public int $months,
        #[DataCollectionOf(ProjectedTenantData::class)]
        public array $tenants,
        #[DataCollectionOf(ScenarioMonthData::class)]
        public array $totals,
        public float $totalUsageCostUsd,
        public bool $readOnly = true,
    ) {}
}
