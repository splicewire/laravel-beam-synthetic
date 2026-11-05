<?php

namespace Splicewire\Beam\Synthetic\Roster;

use Splicewire\Beam\Synthetic\Data\SyntheticTenantBlueprintData;

/**
 * One synthetic tenant a {@see SyntheticRoster} describes: the tenant id it is materialized (or projected) under, the
 * name it carries, the blueprint whose story it tells, and how many months after the roster's start that story begins.
 *
 * The tenant id is also the curve's seed, so two members read from one blueprint replay different curves.
 */
final class RosterMember
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $name,
        public readonly SyntheticTenantBlueprintData $blueprint,
        public readonly int $offsetMonths = 0,
        public readonly ?string $portfolioId = null,
    ) {}
}
