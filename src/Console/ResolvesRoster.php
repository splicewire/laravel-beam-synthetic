<?php

namespace Splicewire\Beam\Synthetic\Console;

use InvalidArgumentException;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\Blueprints\PortfolioRegistry;
use Splicewire\Beam\Synthetic\Roster\SyntheticRoster;

/**
 * The `{blueprint}` / `--portfolio=` pair the synthetic commands share: exactly one of the two, resolved against the
 * host's registries. A name the host does not declare is refused by name, which reads differently from an empty set.
 */
trait ResolvesRoster
{
    /** @throws InvalidArgumentException naming what was asked for */
    protected function roster(BlueprintRegistry $blueprints, PortfolioRegistry $portfolios): SyntheticRoster
    {
        $blueprintId = $this->argument('blueprint');
        $portfolioId = $this->option('portfolio');

        if ($blueprintId !== null && $portfolioId !== null) {
            throw new InvalidArgumentException('Name a blueprint or a --portfolio, not both.');
        }

        if ($portfolioId !== null) {
            $portfolio = $portfolios->get((string) $portfolioId);

            if ($portfolio === null) {
                throw new InvalidArgumentException("No portfolio with id '{$portfolioId}'. Run synthetic:project with no argument to list them.");
            }

            return SyntheticRoster::ofPortfolio($portfolio, $blueprints);
        }

        $blueprint = $blueprints->get((string) $blueprintId);

        if ($blueprint === null) {
            throw new InvalidArgumentException("No blueprint with id '{$blueprintId}'. Run synthetic:project with no argument to list them.");
        }

        return SyntheticRoster::ofBlueprint($blueprint);
    }
}
