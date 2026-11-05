<?php

use Splicewire\Beam\Synthetic\Curve\CurveEnvelope;
use Splicewire\Beam\Synthetic\Curve\CurveKernel;
use Splicewire\Beam\Synthetic\Curve\CurvePeriod;
use Splicewire\Beam\Synthetic\Curve\CurvePoint;
use Splicewire\Beam\Synthetic\Curve\GrowthShape;

/*
 * The kernel on its own terms: no months, no money, no seats. Periods here are opaque keys, which is
 * the point; a capacity model or a load forecast calls it exactly this way.
 */

/** @return list<CurvePeriod> */
function opaquePeriods(int $n): array
{
    return array_map(fn (int $i): CurvePeriod => new CurvePeriod("p{$i}", $i % 12), range(0, $n - 1));
}

it('expands every shape deterministically, one point per period, keys carried through', function (GrowthShape $shape) {
    $envelope = new CurveEnvelope($shape, opaquePeriods(12), scalarTarget: 100.0, rampFrom: 2, rampTo: 20);
    $kernel = new CurveKernel;

    $first = $kernel->expand($envelope, 'seed-a');

    expect($first)->toEqual($kernel->expand($envelope, 'seed-a'))
        ->and($first)->toHaveCount(12)
        ->and($first)->each->toBeInstanceOf(CurvePoint::class)
        ->and(array_map(fn (CurvePoint $p) => $p->period, $first))->toBe(array_map(fn (CurvePeriod $p) => $p->key, opaquePeriods(12)))
        ->and($first[0]->integer)->toBe(2)
        ->and($first[11]->integer)->toBe(20);
})->with(GrowthShape::cases());

it('gives a different curve for a different seed (negative control)', function (GrowthShape $shape) {
    $envelope = new CurveEnvelope($shape, opaquePeriods(12), scalarTarget: 100.0, rampFrom: 2, rampTo: 20);
    $kernel = new CurveKernel;

    expect($kernel->expand($envelope, 'seed-a'))->not->toEqual($kernel->expand($envelope, 'seed-b'));
})->with(GrowthShape::cases());

it('zeroes every period after the cutoff and keeps the cutoff period live', function () {
    $kernel = new CurveKernel;
    $points = $kernel->expand(new CurveEnvelope(GrowthShape::SteadyRamp, opaquePeriods(6), 10.0, 1, 5, cutoffPeriod: 2), 's');

    expect($points[2]->scalar)->toBeGreaterThan(0.0)
        ->and($points[2]->integer)->toBeGreaterThan(0);

    foreach (array_slice($points, 3) as $dead) {
        expect($dead->scalar)->toBe(0.0)->and($dead->integer)->toBe(0);
    }

    $all = $kernel->expand(new CurveEnvelope(GrowthShape::SteadyRamp, opaquePeriods(3), 10.0, 1, 5, cutoffPeriod: -1), 's');
    expect(array_map(fn (CurvePoint $p) => [$p->scalar, $p->integer], $all))->toBe([[0.0, 0], [0.0, 0], [0.0, 0]]);
});

it('reads the cycle phase only for the seasonal shape', function () {
    $kernel = new CurveKernel;
    $shifted = array_map(fn (int $i): CurvePeriod => new CurvePeriod("p{$i}", ($i + 6) % 12), range(0, 11));

    $seasonalA = $kernel->expand(new CurveEnvelope(GrowthShape::Seasonal, opaquePeriods(12), 100.0, 1, 5), 's');
    $seasonalB = $kernel->expand(new CurveEnvelope(GrowthShape::Seasonal, $shifted, 100.0, 1, 5), 's');
    $rampA = $kernel->expand(new CurveEnvelope(GrowthShape::SteadyRamp, opaquePeriods(12), 100.0, 1, 5), 's');
    $rampB = $kernel->expand(new CurveEnvelope(GrowthShape::SteadyRamp, $shifted, 100.0, 1, 5), 's');

    expect($seasonalA)->not->toEqual($seasonalB)
        ->and($rampA)->toEqual($rampB);
});

it('returns no points for no periods', function () {
    expect((new CurveKernel)->expand(new CurveEnvelope(GrowthShape::SteadyRamp, [], 10.0, 1, 5), 's'))->toBe([]);
});

it('refuses a malformed envelope', function () {
    expect(fn () => new CurveEnvelope(GrowthShape::SteadyRamp, ['a' => new CurvePeriod('a')], 1.0, 0, 1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CurveEnvelope(GrowthShape::SteadyRamp, ['p0'], 1.0, 0, 1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CurveEnvelope(GrowthShape::SteadyRamp, [], 1.0, 0, 1, cutoffPeriod: -2))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CurvePeriod('p', -1))
        ->toThrow(InvalidArgumentException::class);
});
