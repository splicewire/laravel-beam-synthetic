<?php

namespace Splicewire\Beam\Synthetic\Extras;

use Splicewire\Beam\Commerce\Subscription;
use Splicewire\Beam\Synthetic\Data\ScenarioMonthData;
use Splicewire\Beam\Synthetic\Data\SyntheticTenantBlueprintData;
use Splicewire\Beam\Synthetic\Roster\RosterMember;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * What a {@see MaterializesSyntheticExtras} is handed once the generic materializer has written a synthetic tenant:
 * the tenant, its blueprint and roster member, the months it was materialized over, its curve, and its plan
 * subscription.
 */
final class SyntheticTenantContext
{
    /**
     * @param  list<string>  $months  the closed months materialized, oldest first
     * @param  array<string, ScenarioMonthData>  $series  the curve over those months, keyed by month
     */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly SyntheticTenantBlueprintData $blueprint,
        public readonly RosterMember $member,
        public readonly array $months,
        public readonly array $series,
        public readonly Subscription $subscription,
    ) {}
}
