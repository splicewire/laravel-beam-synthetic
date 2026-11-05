<?php

namespace Splicewire\Beam\Synthetic\Curve;

/**
 * The shape a curve takes across its periods. Each shape drives two series at once: a scalar that
 * moves toward the envelope's target, and an integer that ramps from `rampFrom` to `rampTo`.
 *
 * - `SteadyRamp`: both series rise, with seeded irregular spacing, the scalar from a quarter of the
 *   target up to the target.
 * - `Seasonal`: the scalar follows a yearly cycle read off each period's phase, with light seeded
 *   jitter; the integer still rises.
 * - `DipThenRecover`: the scalar climbs, collapses across the middle of the window, then recovers; the
 *   integer still rises, so only the scalar dips.
 *
 * The names describe the math, not what a caller means by it. A host reads the scalar as money, usage
 * or load, and the integer as seats, instances or headcount.
 */
enum GrowthShape: string
{
    case SteadyRamp = 'steady-ramp';
    case Seasonal = 'seasonal';
    case DipThenRecover = 'dip-then-recover';
}
