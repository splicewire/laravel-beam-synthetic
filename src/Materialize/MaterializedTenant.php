<?php

namespace Splicewire\Beam\Synthetic\Materialize;

/**
 * What materializing one synthetic tenant wrote: a receipt for the command to print and a test to read.
 */
final class MaterializedTenant
{
    /**
     * @param  list<string>  $months  the closed months materialized, oldest first
     * @param  list<string>  $billsGenerated  the periods a bill was generated for in this run
     * @param  list<string>  $billsSkipped  the periods already billed, left alone
     * @param  list<string>  $extras  the names of the extras that ran
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $blueprintId,
        public readonly bool $created,
        public readonly string $planSlug,
        public readonly array $months,
        public readonly int $seats,
        public readonly int $usageMonths,
        public readonly bool $storageReady,
        public readonly array $billsGenerated = [],
        public readonly array $billsSkipped = [],
        public readonly array $extras = [],
    ) {}
}
