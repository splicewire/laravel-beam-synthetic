<?php

namespace Splicewire\Beam\Synthetic\Data;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use InvalidArgumentException;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Synthetic\Enums\SyntheticScenario;

/**
 * The committed, declarative description of one synthetic tenant's story: who it is, which plan it
 * is on, its scenario and the curve's envelope. It is the one payload every producer emits (a
 * committed YAML file, an authoring UI, a host's LLM generator), and replay reads it back. It never
 * carries database rows.
 *
 * ## Replay validation lives here
 *
 * {@see self::from()} rejects a payload that could not replay, before any property is cast, with a
 * message that names the offending value and the valid ones. Every producer gets the same check, so a
 * generator does not need its own copy.
 *
 * - `scenario` must be one of {@see SyntheticScenario}'s slugs.
 * - The usage target may be spelled `targetMonthlyUsageCostUsd` or, for one release,
 *   `targetMonthlyTokenCostUsd`. A payload carrying both is rejected rather than having one silently
 *   win.
 *
 * ## Usage is metered, and the meter is named
 *
 * `targetMonthlyUsageCostUsd` is the mature monthly usage cost the curve targets, billed against
 * `usageMeter`. A blueprint that names no meter takes `config('beam.synthetic.usage_meter')`, whose
 * default is `openai.tokens`.
 *
 * ## Hard churn
 *
 * `endedAtMonth` is the 1-based month of the window in which the tenant actually left: its
 * subscription ends, its seats are released and its usage stops after that month. `null` means it is
 * still a paying customer. It layers on any scenario and is not the `churned-then-resumed` scenario,
 * where the tenant goes quiet but keeps paying.
 */
#[TypeScript]
class SyntheticTenantBlueprintData extends BeamData
{
    /** The old name of the usage target, accepted as input for one release. */
    public const LEGACY_USAGE_TARGET_KEY = 'targetMonthlyTokenCostUsd';

    public const DEFAULT_USAGE_METER = 'openai.tokens';

    public string $usageMeter;

    public function __construct(
        public string $id,
        public string $name,
        public string $ownerName,
        public string $ownerEmail,
        public ?string $scaffoldPackSlug,
        public string $planSlug,
        public SyntheticScenario $scenario,
        public int $windowMonths,
        #[MapInputName(self::LEGACY_USAGE_TARGET_KEY)]
        public float $targetMonthlyUsageCostUsd,
        public int $startingSeats,
        public int $endingSeats,
        public ?int $endedAtMonth = null,
        ?string $usageMeter = null,
    ) {
        $this->usageMeter = $usageMeter ?? self::defaultUsageMeter();
    }

    /**
     * The curve's envelope for a window that starts at `$startedAt`. The anchor is supplied when the
     * blueprint is replayed, relative to now, so the story stays current instead of ageing from a fixed
     * date.
     */
    public function envelope(CarbonImmutable $startedAt): ScenarioEnvelopeData
    {
        return new ScenarioEnvelopeData(
            startedAt: $startedAt,
            endedAt: $this->endedAtMonth !== null
                ? $startedAt->addMonths($this->endedAtMonth - 1)->endOfMonth()
                : null,
            scenario: $this->scenario,
            targetMonthlyUsageCostUsd: $this->targetMonthlyUsageCostUsd,
            startingSeats: $this->startingSeats,
            endingSeats: $this->endingSeats,
        );
    }

    /**
     * The replay checks, run on every `from()` before casting. See the class docblock.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        self::assertReplayableScenario($properties['scenario'] ?? null);

        if (array_key_exists('targetMonthlyUsageCostUsd', $properties) && array_key_exists(self::LEGACY_USAGE_TARGET_KEY, $properties)) {
            $id = is_scalar($properties['id'] ?? null) ? (string) $properties['id'] : '(no id)';

            throw new InvalidArgumentException(
                "Blueprint '{$id}' sets both targetMonthlyUsageCostUsd and ".self::LEGACY_USAGE_TARGET_KEY.'. '
                .'Keep targetMonthlyUsageCostUsd; '.self::LEGACY_USAGE_TARGET_KEY.' is its old name.',
            );
        }

        return $properties;
    }

    /**
     * A scenario a blueprint names must be one the expander can replay. An unknown slug fails here,
     * when the blueprint is read, rather than as an opaque cast error when it is materialized.
     */
    public static function assertReplayableScenario(mixed $scenario): void
    {
        if ($scenario instanceof SyntheticScenario || (is_string($scenario) && SyntheticScenario::tryFrom($scenario) !== null)) {
            return;
        }

        $slug = is_scalar($scenario) ? (string) $scenario : get_debug_type($scenario);

        throw new InvalidArgumentException(
            "Blueprint has an unknown scenario '{$slug}'. Valid scenarios: ".SyntheticScenario::slugs().'.',
        );
    }

    /** `beam.synthetic.usage_meter`, or `openai.tokens` where no config is bound. */
    public static function defaultUsageMeter(): string
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return self::DEFAULT_USAGE_METER;
        }

        $meter = $container->make('config')->get('beam.synthetic.usage_meter');

        return is_string($meter) && $meter !== '' ? $meter : self::DEFAULT_USAGE_METER;
    }
}
