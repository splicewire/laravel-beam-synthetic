<?php

namespace Splicewire\Beam\Synthetic\Roster;

use Carbon\CarbonImmutable;
use Splicewire\Beam\Synthetic\Data\ProjectedTenantData;
use Splicewire\Beam\Synthetic\Data\ScenarioMonthData;
use Splicewire\Beam\Synthetic\Data\ScenarioProjectionData;
use Splicewire\Beam\Synthetic\ScenarioExpander;

/**
 * Runs a {@see SyntheticRoster} FORWARD from an anchor month, in memory: the same members, seeds and curves the
 * materializer writes as history, read as a projection instead.
 *
 * It resolves no model, repository or connection; its one collaborator is the pure {@see ScenarioExpander}. If it
 * ever needs a database, it has stopped being a projection. `tests/Console/ProjectCommandTest.php` holds it to zero
 * queries.
 */
class ScenarioProjector
{
    public function __construct(private readonly ScenarioExpander $expander) {}

    public function project(SyntheticRoster $roster, CarbonImmutable $anchor, ?int $months = null): ScenarioProjectionData
    {
        $window = $roster->forwardMonths($anchor, $months);
        $totals = array_fill_keys($window, ['usage' => 0.0, 'seats' => 0]);
        $tenants = [];

        foreach ($roster->members as $member) {
            $series = $roster->series($this->expander, $member, $roster->monthsFor($member, $window));

            foreach ($series as $month => $point) {
                $totals[$month]['usage'] += $point->usageCostUsd;
                $totals[$month]['seats'] += $point->seatCount;
            }

            $tenants[] = new ProjectedTenantData(
                tenant: $member->tenantId,
                blueprint: $member->blueprint->id,
                scenario: $member->blueprint->scenario->value,
                plan: $member->blueprint->planSlug,
                usageMeter: $member->blueprint->usageMeter,
                startsAt: array_key_first($series),
                projection: array_values($series),
                totalUsageCostUsd: round(array_sum(array_map(fn (ScenarioMonthData $m): float => $m->usageCostUsd, $series)), 2),
            );
        }

        $monthTotals = [];
        foreach ($totals as $month => $total) {
            $monthTotals[] = new ScenarioMonthData(month: $month, usageCostUsd: round($total['usage'], 2), seatCount: $total['seats']);
        }

        return new ScenarioProjectionData(
            blueprint: $roster->portfolioId === null ? $roster->members[0]->blueprint->id : null,
            portfolio: $roster->portfolioId,
            from: $window[0],
            months: count($window),
            tenants: $tenants,
            totals: $monthTotals,
            totalUsageCostUsd: round(array_sum(array_map(fn (ScenarioMonthData $m): float => $m->usageCostUsd, $monthTotals)), 2),
        );
    }
}
