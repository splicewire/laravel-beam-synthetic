<?php

namespace Splicewire\Beam\Synthetic\Blueprints;

use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Rushing\Popcorn\Registries\Registry;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;

/**
 * The scenario portfolios this host can read, keyed by portfolio id: the sibling of
 * {@see BlueprintRegistry}, filled the same way from `beam.synthetic.portfolio_paths`, whose default
 * is this package's Demo portfolio.
 *
 * Duplicate ids are rejected for the same reason as blueprints. A portfolio names its blueprints by id;
 * {@see BlueprintRegistry::missingFrom()} reports the ones a host does not have.
 */
#[IsRegistry(
    root: 'beam.synthetic.portfolios',
    entryType: ScenarioPortfolioData::class,
    onKeyDuplicate: OnKeyDuplicate::Reject,
    populationRequirement: PopulationRequirement::Optional,
    description: 'scenario portfolios of synthetic-tenant blueprints, keyed by portfolio id',
)]
class PortfolioRegistry implements Gated, Registry
{
    use DelegatesToStore;

    public function get(string $id): ?ScenarioPortfolioData
    {
        $portfolio = $this->tryResolve($id);

        return $portfolio instanceof ScenarioPortfolioData ? $portfolio : null;
    }

    /** @return array<string, ScenarioPortfolioData> id => portfolio, in registration order */
    public function all(): array
    {
        $all = [];

        foreach ($this->ids() as $id) {
            $all[$id] = $this->resolve($id);
        }

        return $all;
    }
}
