<?php

namespace Splicewire\Beam\Synthetic;

use Splicewire\Beam\Synthetic\Curve\CurveEnvelope;
use Splicewire\Beam\Synthetic\Curve\CurveKernel;
use Splicewire\Beam\Synthetic\Curve\CurvePeriod;
use Splicewire\Beam\Synthetic\Curve\CurvePoint;
use Splicewire\Beam\Synthetic\Data\ScenarioEnvelopeData;
use Splicewire\Beam\Synthetic\Data\ScenarioMonthData;

/**
 * The billing reading of the {@see CurveKernel}: a synthetic tenant's envelope and a list of months
 * in, one {@see ScenarioMonthData} per month out, keyed by month.
 *
 * This class owns the vocabulary and the calendar; the kernel owns the math. It maps:
 *
 * - the scenario to its growth shape;
 * - each `YYYY-MM` month to a period whose cycle phase is its month of the year (January is 0), which
 *   is what the seasonal shape reads;
 * - the usage-cost target to the kernel's scalar target, and the seat ramp to its integer ramp;
 * - `endedAt` to the kernel's cutoff: the last month that is not after the churn month.
 *
 * Pure and side-effect-free: the same envelope, seed and months always return the same series, which
 * is what lets a seeded curve replay across deploys and a projection read it with no database at all.
 */
class ScenarioExpander
{
    public function __construct(private readonly CurveKernel $kernel = new CurveKernel) {}

    /**
     * @param  list<string>  $months  ordered `YYYY-MM` keys
     * @return array<string, ScenarioMonthData>
     */
    public function expand(ScenarioEnvelopeData $envelope, string $seed, array $months): array
    {
        $points = $this->kernel->expand($this->curve($envelope, $months), $seed);

        $series = [];
        foreach ($points as $point) {
            $series[$point->period] = $this->month($point);
        }

        return $series;
    }

    /**
     * The neutral envelope for a billing envelope over `$months`.
     *
     * @param  list<string>  $months  ordered `YYYY-MM` keys
     */
    public function curve(ScenarioEnvelopeData $envelope, array $months): CurveEnvelope
    {
        return new CurveEnvelope(
            shape: $envelope->scenario->shape(),
            periods: array_map(
                fn (string $month): CurvePeriod => new CurvePeriod($month, (int) substr($month, 5, 2) - 1),
                array_values($months),
            ),
            scalarTarget: $envelope->targetMonthlyUsageCostUsd,
            rampFrom: $envelope->startingSeats,
            rampTo: $envelope->endingSeats,
            cutoffPeriod: $this->cutoff($envelope, array_values($months)),
            cycleLength: 12,
        );
    }

    /**
     * The index of the last month on or before the churn month: every month strictly after it is
     * dead, and the churn month itself stays live (it was billed for part of the period). `-1` when
     * the churn month falls before the window.
     *
     * @param  list<string>  $months
     */
    private function cutoff(ScenarioEnvelopeData $envelope, array $months): ?int
    {
        if ($envelope->endedAt === null) {
            return null;
        }

        $churnMonth = $envelope->endedAt->format('Y-m');
        $cutoff = -1;

        foreach ($months as $i => $month) {
            if ($month <= $churnMonth) {
                $cutoff = $i;
            }
        }

        return $cutoff;
    }

    private function month(CurvePoint $point): ScenarioMonthData
    {
        return new ScenarioMonthData(
            month: $point->period,
            usageCostUsd: $point->scalar,
            seatCount: $point->integer,
        );
    }
}
