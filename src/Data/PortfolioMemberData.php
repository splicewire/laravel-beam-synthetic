<?php

namespace Splicewire\Beam\Synthetic\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One line of a portfolio: which blueprint (by id), how many tenants to read from it, and how many
 * months after the portfolio's start the first of them begins.
 */
#[TypeScript, Title('Portfolio member')]
class PortfolioMemberData extends BeamData
{
    public function __construct(
        public string $blueprint,
        public int $count = 1,
        public int $startOffsetMonths = 0,
    ) {}
}
