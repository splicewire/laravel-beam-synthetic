<?php

namespace Splicewire\Beam\Synthetic\Blueprints;

use Illuminate\Container\Container;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Rushing\Popcorn\Registries\Registry;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;
use Splicewire\Beam\Synthetic\Store\StoredPortfolios;

/**
 * The scenario portfolios this host can read, keyed by portfolio id: the sibling of
 * {@see BlueprintRegistry}, filled the same way from `beam.synthetic.portfolio_paths`, whose default
 * is this package's Demo portfolio.
 *
 * Duplicate ids are rejected for the same reason as blueprints. A portfolio names its blueprints by id;
 * {@see BlueprintRegistry::missingFrom()} reports the ones a host does not have.
 *
 * ## Declared and stored portfolios
 *
 * The registry's own entries are the DECLARED portfolios: the files, and any a package or host registers in code.
 * {@see get()} and {@see all()} also read the portfolios a host saved at runtime ({@see StoredPortfolios}, the
 * central `beam_synthetic_portfolios` table), after the declared ones. A declared id always wins: a stored row
 * under a declared id is never read, and saving one is refused. So a lookup of a declared id never touches the
 * database, and {@see declared()} and {@see declares()} never do; only a miss, or {@see all()}, asks the store.
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

    /** A declared portfolio, else a stored one, else null (also for an id that is not a legal registry key). */
    public function get(string $id): ?ScenarioPortfolioData
    {
        if (! self::legal($id)) {
            return null;
        }

        $portfolio = $this->tryResolve($id);

        if ($portfolio instanceof ScenarioPortfolioData) {
            return $portfolio;
        }

        return $this->stored()?->find($id);
    }

    /** @return array<string, ScenarioPortfolioData> id => portfolio: the declared ones in registration order, then the stored ones */
    public function all(): array
    {
        return [...$this->declared(), ...($this->stored()?->all() ?? [])];
    }

    /** @return array<string, ScenarioPortfolioData> id => portfolio, the declared ones only (files and code), in registration order */
    public function declared(): array
    {
        $all = [];

        foreach ($this->ids() as $id) {
            $all[$id] = $this->resolve($id);
        }

        return $all;
    }

    /** Whether `$id` is a declared portfolio (a file or a code registration), which no stored one can replace. */
    public function declares(string $id): bool
    {
        return self::legal($id) && $this->has($id);
    }

    /** Whether `$id` reads from the store rather than from a declaration. */
    public function isStored(string $id): bool
    {
        return ! $this->declares($id) && $this->stored()?->find($id) !== null;
    }

    /** The first portfolio's id: the first declared one, else the first stored one, else null. */
    public function firstId(): ?string
    {
        return $this->ids()[0] ?? array_key_first($this->stored()?->all() ?? []);
    }

    /**
     * Whether `$id` can be a registry key at all. Ids reach this registry from request bodies and cookies, and popcorn
     * throws on an illegal key; an id that cannot be a key is simply not a portfolio here.
     */
    private static function legal(string $id): bool
    {
        return Key::tryParse($id) !== null;
    }

    /** The host's stored portfolios, or null when it keeps none (unbound, or `beam.synthetic.portfolio_store` false). */
    private function stored(): ?StoredPortfolios
    {
        $app = Container::getInstance();

        if (! $app->bound(StoredPortfolios::class)) {
            return null;
        }

        $stored = $app->make(StoredPortfolios::class);

        return $stored->enabled() ? $stored : null;
    }
}
