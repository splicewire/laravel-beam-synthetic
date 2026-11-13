<?php

namespace Splicewire\Beam\Synthetic\Data;

use InvalidArgumentException;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Synthetic\Blueprints\BlueprintRegistry;

/**
 * A named set of blueprints read together: a scenario a board can be shown, or the population a
 * projection runs forward.
 *
 * `members` names blueprints by id, with a count and a start offset each. `churnRate` and
 * `expansionRate` are optional monthly rates (fractions from 0 to 1) a projection applies on top of
 * each member's curve; `null` means the portfolio states none.
 *
 * {@see self::from()} rejects a portfolio with no members, a member with a count below 1 or a negative
 * offset, and a rate outside 0 to 1. Whether each member names a registered blueprint is a question for
 * {@see BlueprintRegistry::missingFrom()}, since a portfolio file
 * does not know which blueprints a host has.
 */
#[TypeScript]
#[Title('Scenario portfolio')]
class ScenarioPortfolioData extends BeamData
{
    /**
     * @param  list<PortfolioMemberData>  $members
     */
    public function __construct(
        public string $id,
        public string $label,
        #[DataCollectionOf(PortfolioMemberData::class)]
        public array $members,
        public ?float $churnRate = null,
        public ?float $expansionRate = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        $id = is_scalar($properties['id'] ?? null) ? (string) $properties['id'] : '(no id)';
        $members = $properties['members'] ?? null;

        if (! is_array($members) || $members === []) {
            throw new InvalidArgumentException("Portfolio '{$id}' has no members.");
        }

        foreach ($members as $i => $member) {
            $member = $member instanceof PortfolioMemberData ? $member->toArray() : $member;

            if (! is_array($member) || ! is_string($member['blueprint'] ?? null) || $member['blueprint'] === '') {
                throw new InvalidArgumentException("Portfolio '{$id}' member {$i} names no blueprint.");
            }

            if (isset($member['count']) && (int) $member['count'] < 1) {
                throw new InvalidArgumentException("Portfolio '{$id}' member '{$member['blueprint']}' has a count below 1.");
            }

            if (isset($member['startOffsetMonths']) && (int) $member['startOffsetMonths'] < 0) {
                throw new InvalidArgumentException("Portfolio '{$id}' member '{$member['blueprint']}' has a negative start offset.");
            }
        }

        foreach (['churnRate', 'expansionRate'] as $rate) {
            $value = $properties[$rate] ?? null;

            if ($value !== null && (! is_numeric($value) || $value < 0 || $value > 1)) {
                throw new InvalidArgumentException("Portfolio '{$id}' {$rate} must be a fraction from 0 to 1.");
            }
        }

        return $properties;
    }

    /** @return list<string> the distinct blueprint ids this portfolio names, in order */
    public function blueprintIds(): array
    {
        return array_values(array_unique(array_map(fn (PortfolioMemberData $m): string => $m->blueprint, $this->members)));
    }
}
