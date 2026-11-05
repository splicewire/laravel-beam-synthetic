<?php

namespace Splicewire\Beam\Synthetic\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Synthetic\Curve\GrowthShape;
use Splicewire\Beam\Synthetic\Data\SyntheticTenantBlueprintData;

/**
 * The lifecycle trajectory of a synthetic tenant, as a blueprint names it. The slugs are the
 * replayable vocabulary: a blueprint whose `scenario` is not one of them is rejected when it is read
 * ({@see SyntheticTenantBlueprintData}).
 *
 * Each case reads one neutral {@see GrowthShape}: usage is the curve's scalar and seats are its
 * integer.
 *
 * - `steady-ramp`: usage and seats both grow.
 * - `seasonal`: usage rises and falls with the calendar year; seats grow.
 * - `churned-then-resumed`: usage nearly stops mid-window, then recovers, while the tenant keeps paying
 *   for its seats. This is a quiet patch, not a departure: a tenant that actually leaves carries
 *   `endedAtMonth` instead, on any scenario.
 */
#[TypeScript]
enum SyntheticScenario: string
{
    case SteadyRamp = 'steady-ramp';
    case Seasonal = 'seasonal';
    case ChurnedThenResumed = 'churned-then-resumed';

    public function shape(): GrowthShape
    {
        return match ($this) {
            self::SteadyRamp => GrowthShape::SteadyRamp,
            self::Seasonal => GrowthShape::Seasonal,
            self::ChurnedThenResumed => GrowthShape::DipThenRecover,
        };
    }

    /** The replayable slugs, comma-separated, for error messages and prompts. */
    public static function slugs(): string
    {
        return implode(', ', array_map(fn (self $s): string => $s->value, self::cases()));
    }
}
