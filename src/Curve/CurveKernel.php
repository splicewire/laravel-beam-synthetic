<?php

namespace Splicewire\Beam\Synthetic\Curve;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The curve kernel: a pure, deterministic expansion of a {@see CurveEnvelope} and a seed into one
 * {@see CurvePoint} per period.
 *
 * The same envelope and seed always return the same points, so a curve seeded from a stable id replays
 * identically across runs and deploys. The kernel reads no clock, no config and no database, and it
 * names no framework or domain type: its only imports are PHP's own `Random` engine. A test enforces
 * that by reading this namespace's `use` lines.
 *
 * Every shape draws from one Mt19937 engine seeded with `crc32($seed)`, in a fixed order. That order
 * is part of the output: drawing one number earlier or later changes every point after it. The golden
 * fixture pins it.
 */
final class CurveKernel
{
    /** Where the `SteadyRamp` scalar starts, as a fraction of the target. */
    private const START_FRACTION = 0.25;

    /** @return list<CurvePoint> */
    public function expand(CurveEnvelope $envelope, string $seed): array
    {
        $points = match ($envelope->shape) {
            GrowthShape::SteadyRamp => $this->steadyRamp($envelope, $seed),
            GrowthShape::Seasonal => $this->seasonal($envelope, $seed),
            GrowthShape::DipThenRecover => $this->dipThenRecover($envelope, $seed),
        };

        return $this->applyCutoff($envelope, $points);
    }

    /**
     * The cutoff is orthogonal to the shape: every period after the cutoff is zeroed in both series,
     * whatever curve it would otherwise carry. The cutoff period itself keeps its values.
     *
     * @param  list<CurvePoint>  $points
     * @return list<CurvePoint>
     */
    private function applyCutoff(CurveEnvelope $envelope, array $points): array
    {
        if ($envelope->cutoffPeriod === null) {
            return $points;
        }

        foreach ($points as $i => $point) {
            if ($i > $envelope->cutoffPeriod) {
                $points[$i] = new CurvePoint($point->period, 0.0, 0);
            }
        }

        return $points;
    }

    /** @return list<CurvePoint> */
    private function steadyRamp(CurveEnvelope $envelope, string $seed): array
    {
        $randomizer = new Randomizer(new Mt19937(crc32($seed)));
        $n = count($envelope->periods);

        $scalarFractions = $this->risingFractions($randomizer, $n);
        $integerFractions = $this->risingFractions($randomizer, $n);

        $base = $envelope->scalarTarget * self::START_FRACTION;
        $scalarGrowth = $envelope->scalarTarget - $base;
        $integerGrowth = $envelope->rampTo - $envelope->rampFrom;

        $points = [];
        foreach ($envelope->periods as $i => $period) {
            $points[] = new CurvePoint(
                period: $period->key,
                scalar: round($base + $scalarGrowth * $scalarFractions[$i], 2),
                integer: $envelope->rampFrom + (int) round($integerGrowth * $integerFractions[$i]),
            );
        }

        return $points;
    }

    /**
     * The scalar follows one cycle per `cycleLength` periods (a sine peaking a quarter of the way
     * through the cycle), so any full-cycle window shows a rise and a fall. Light seeded jitter keeps it
     * from being a clean sine. The integer still rises steadily.
     *
     * @return list<CurvePoint>
     */
    private function seasonal(CurveEnvelope $envelope, string $seed): array
    {
        $randomizer = new Randomizer(new Mt19937(crc32($seed)));
        $n = count($envelope->periods);
        $integerFractions = $this->risingFractions($randomizer, $n);
        $integerGrowth = $envelope->rampTo - $envelope->rampFrom;

        $points = [];
        foreach ($envelope->periods as $i => $period) {
            $cycle = 0.5 + 0.45 * sin(2 * M_PI * $period->phase / $envelope->cycleLength);
            $jitter = ($randomizer->nextFloat() - 0.5) * 0.08;
            $factor = max(0.05, min(1.0, $cycle + $jitter));

            $points[] = new CurvePoint(
                period: $period->key,
                scalar: round($envelope->scalarTarget * $factor, 2),
                integer: $envelope->rampFrom + (int) round($integerGrowth * $integerFractions[$i]),
            );
        }

        return $points;
    }

    /**
     * The scalar climbs, collapses to a trough across the middle of the window (from 40% to 70% of the
     * periods), then recovers. The integer keeps rising throughout, so only the scalar dips.
     *
     * @return list<CurvePoint>
     */
    private function dipThenRecover(CurveEnvelope $envelope, string $seed): array
    {
        $randomizer = new Randomizer(new Mt19937(crc32($seed)));
        $n = count($envelope->periods);
        $integerFractions = $this->risingFractions($randomizer, $n);
        $integerGrowth = $envelope->rampTo - $envelope->rampFrom;

        $dipStart = (int) floor($n * 0.4);
        $dipEnd = (int) floor($n * 0.7); // exclusive

        $points = [];
        foreach ($envelope->periods as $i => $period) {
            if ($i < $dipStart) {
                $t = $dipStart > 1 ? $i / ($dipStart - 1) : 1.0;
                $base = 0.45 + 0.40 * $t;
            } elseif ($i < $dipEnd) {
                $base = 0.05;
            } else {
                $span = $n - 1 - $dipEnd;
                $t = $span > 0 ? ($i - $dipEnd) / $span : 1.0;
                $base = 0.70 + 0.30 * $t;
            }
            $jitter = ($randomizer->nextFloat() - 0.5) * 0.04;
            $factor = max(0.02, min(1.0, $base + $jitter));

            $points[] = new CurvePoint(
                period: $period->key,
                scalar: round($envelope->scalarTarget * $factor, 2),
                integer: $envelope->rampFrom + (int) round($integerGrowth * $integerFractions[$i]),
            );
        }

        return $points;
    }

    /**
     * A non-decreasing sequence of length `$n` from 0.0 to 1.0, with seeded irregular spacing so a
     * curve looks organic rather than linear.
     *
     * @return list<float>
     */
    private function risingFractions(Randomizer $randomizer, int $n): array
    {
        if ($n <= 1) {
            return $n === 1 ? [1.0] : [];
        }

        $weights = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $weights[] = $randomizer->nextFloat() + 0.0001;
        }
        $total = array_sum($weights);

        $fractions = [0.0];
        $acc = 0.0;
        foreach ($weights as $weight) {
            $acc += $weight;
            $fractions[] = $acc / $total;
        }

        return $fractions;
    }
}
