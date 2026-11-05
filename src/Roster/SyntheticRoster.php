<?php

namespace Splicewire\Beam\Synthetic\Roster;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;
use Splicewire\Beam\Synthetic\Data\ScenarioMonthData;
use Splicewire\Beam\Synthetic\Data\ScenarioPortfolioData;
use Splicewire\Beam\Synthetic\Data\SyntheticTenantBlueprintData;
use Splicewire\Beam\Synthetic\ScenarioExpander;

/**
 * Who a synthesize run is about, and over which months: one blueprint read as one tenant, or a portfolio read as
 * every tenant its members describe. The materializer writes a roster's HISTORY; the projection reads it FORWARD.
 * Both read the same members and the same curves, so a materialized tenant and its projection cannot disagree on
 * who it is or which seed it replays.
 *
 * Pure: it reads no database, and the portfolio form takes the blueprint registry it resolves members against.
 *
 * ## The window
 *
 * A roster spans {@see length()} months. For one blueprint that is its own `windowMonths`. For a portfolio it is the
 * longest member's offset plus its blueprint's `windowMonths`, so the latest-starting member still tells its whole
 * story. Each member starts `offsetMonths` into the window and stays to its end, unless its blueprint churns it
 * (`endedAtMonth`, counted from the member's own start).
 *
 * ## Tenant ids
 *
 * A blueprint read on its own is materialized under its own id, as the flagship's seeder always did. A portfolio
 * member is materialized `count` times, as `{blueprint}-1` … `{blueprint}-{count}`, so a portfolio never rewrites
 * the tenant its blueprint was materialized as on its own.
 *
 * ## Two month builders, deliberately
 *
 * {@see historyMonths()} walks BACK from now: it describes a history that has already happened, so every month in it
 * is closed. {@see forwardMonths()} walks forward from an anchor. They are kept as two methods rather than one with a
 * direction flag, because sharing a builder with a sign flag is how a projection quietly starts writing history.
 */
final class SyntheticRoster
{
    /**
     * @param  list<RosterMember>  $members
     */
    private function __construct(
        public readonly array $members,
        private readonly int $length,
        public readonly ?string $portfolioId = null,
    ) {}

    public static function ofBlueprint(SyntheticTenantBlueprintData $blueprint): self
    {
        return new self(
            members: [new RosterMember($blueprint->id, $blueprint->name, $blueprint)],
            length: max(1, $blueprint->windowMonths),
        );
    }

    /**
     * @throws InvalidArgumentException when a member names a blueprint the registry does not hold (all of them are named)
     */
    public static function ofPortfolio(ScenarioPortfolioData $portfolio, BlueprintRegistry $blueprints): self
    {
        $missing = $blueprints->missingFrom($portfolio);

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                "Portfolio '%s' names blueprints this host does not declare: %s.",
                $portfolio->id,
                implode(', ', $missing),
            ));
        }

        $members = [];
        $length = 1;

        foreach ($portfolio->members as $member) {
            $blueprint = $blueprints->get($member->blueprint);

            foreach (range(1, $member->count) as $k) {
                $members[] = new RosterMember(
                    tenantId: "{$blueprint->id}-{$k}",
                    name: "{$blueprint->name} {$k}",
                    blueprint: $blueprint,
                    offsetMonths: $member->startOffsetMonths,
                    portfolioId: $portfolio->id,
                );
            }

            $length = max($length, $member->startOffsetMonths + $blueprint->windowMonths);
        }

        return new self($members, $length, $portfolio->id);
    }

    /** How many months the roster spans by default. */
    public function length(): int
    {
        return $this->length;
    }

    /**
     * The {@see length()} closed months immediately before the month `$now` falls in, oldest first: the history a
     * materialized roster has already lived through.
     *
     * @return list<string> YYYY-MM
     */
    public function historyMonths(CarbonImmutable $now): array
    {
        $current = $now->startOfMonth();

        return array_map(
            fn (int $back): string => $current->subMonths($back)->format('Y-m'),
            range($this->length, 1),
        );
    }

    /**
     * `$length` months (default {@see length()}) starting at the month `$anchor` falls in: the months a projection
     * runs forward over.
     *
     * @return list<string> YYYY-MM
     */
    public function forwardMonths(CarbonImmutable $anchor, ?int $length = null): array
    {
        $start = $anchor->startOfMonth();

        return array_map(
            fn (int $ahead): string => $start->addMonths($ahead)->format('Y-m'),
            range(0, max(1, $length ?? $this->length) - 1),
        );
    }

    /**
     * The months of `$window` a member is present for: from its offset to the window's end. Empty when it starts
     * after the window ends.
     *
     * @param  list<string>  $window
     * @return list<string>
     */
    public function monthsFor(RosterMember $member, array $window): array
    {
        return array_values(array_slice($window, $member->offsetMonths));
    }

    /**
     * The member's curve over `$months`, seeded by its tenant id. The envelope is anchored on the first month, so the
     * blueprint's story (and its `endedAtMonth`) starts where the member does.
     *
     * @param  list<string>  $months  ordered YYYY-MM keys, at least one
     * @return array<string, ScenarioMonthData>
     */
    public function series(ScenarioExpander $expander, RosterMember $member, array $months): array
    {
        if ($months === []) {
            return [];
        }

        $startedAt = CarbonImmutable::createFromFormat('!Y-m', $months[0])->startOfMonth();

        return $expander->expand($member->blueprint->envelope($startedAt), $member->tenantId, $months);
    }

    /**
     * Every distinct plan slug the roster's blueprints name, in first-seen order.
     *
     * @return list<string>
     */
    public function planSlugs(): array
    {
        return array_values(array_unique(array_map(fn (RosterMember $m): string => $m->blueprint->planSlug, $this->members)));
    }
}
