<?php

namespace Splicewire\Beam\Synthetic\Blueprints;

use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Rushing\Popcorn\Registries\Registry;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;
use Splicewire\Beam\Synthetic\Data\SyntheticTenantBlueprintData;

/**
 * The synthetic-tenant blueprints this host can replay, keyed by blueprint id.
 *
 * A host declares directories of blueprint files in `beam.synthetic.blueprint_paths`; the default is
 * this package's three Demo blueprints. The files are read when the registry is first resolved, through
 * a {@see DirectoryRegistrar}. A package or host may also register a blueprint object in code.
 *
 * Duplicate ids are rejected, not superseded: the id seeds the curve and names the blueprint in a
 * portfolio, so a second blueprint under a taken id is a mistake to report, not an override to apply.
 * To drop the Demo set, declare `blueprint_paths` without the Demo directory.
 *
 * Ids are chosen by blueprint authors and portfolio files, so a portfolio's lookups go through
 * {@see get()} (null on a miss) and {@see missingFrom()}, never a throwing read.
 */
#[IsRegistry(
    root: 'beam.synthetic.blueprints',
    entryType: SyntheticTenantBlueprintData::class,
    onKeyDuplicate: OnKeyDuplicate::Reject,
    populationRequirement: PopulationRequirement::Optional,
    description: 'synthetic-tenant blueprints a host can replay, keyed by blueprint id',
)]
class BlueprintRegistry implements Gated, Registry
{
    use DelegatesToStore;

    public function get(string $id): ?SyntheticTenantBlueprintData
    {
        $blueprint = $this->tryResolve($id);

        return $blueprint instanceof SyntheticTenantBlueprintData ? $blueprint : null;
    }

    /** @return array<string, SyntheticTenantBlueprintData> id => blueprint, in registration order */
    public function all(): array
    {
        $all = [];

        foreach ($this->ids() as $id) {
            $all[$id] = $this->resolve($id);
        }

        return $all;
    }

    /**
     * The blueprint ids a portfolio names that this registry does not hold. Empty when every member
     * resolves.
     *
     * @return list<string>
     */
    public function missingFrom(ScenarioPortfolioData $portfolio): array
    {
        return array_values(array_filter($portfolio->blueprintIds(), fn (string $id): bool => ! $this->has($id)));
    }
}
