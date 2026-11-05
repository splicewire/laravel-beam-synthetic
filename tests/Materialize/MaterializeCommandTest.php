<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Commerce\Bill;
use Splicewire\Beam\Commerce\CentralUsageSummary;
use Splicewire\Beam\Commerce\Plan;
use Splicewire\Beam\Synthetic\Tests\Support\SyntheticHost;
use Splicewire\Beam\Tenancy\Models\TenantMembership;
use Splicewire\Beam\Tenancy\Tenant;

uses(SyntheticHost::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $this->seedDemoPlans();
});

it('materializes a blueprint, then reports the same rows as updated on a re-run', function () {
    // One table row is one write, so read the whole output rather than stacking expectations on it.
    $run = function (): string {
        expect(Artisan::call('synthetic:materialize', ['blueprint' => 'demo-quillmere-studio', '--bills' => true]))->toBe(0);

        return Artisan::output();
    };

    expect($run())->toContain('2025-11..2026-10')->toContain('12 new, 0 kept')->toContain('| created |');

    $counts = fn () => [Tenant::count(), TenantMembership::count(), CentralUsageSummary::count(), Bill::count()];
    $first = $counts();

    expect($run())->toContain('0 new, 12 kept')->toContain('| updated |')
        ->and($counts())->toBe($first)
        ->and($first[0])->toBe(1)
        ->and($first[2])->toBe(12)
        ->and($first[3])->toBe(12);
});

it('materializes a portfolio', function () {
    $this->artisan('synthetic:materialize', ['--portfolio' => 'demo-mixed-book'])
        ->assertSuccessful()
        ->expectsOutputToContain('9 synthetic tenant(s) materialized.');

    expect(Tenant::query()->synthetic()->count())->toBe(9);
});

it('fails, naming the missing plan, and writes nothing', function () {
    Plan::where('slug', 'starter')->delete();

    $this->artisan('synthetic:materialize', ['blueprint' => 'demo-tessaline-works'])
        ->assertFailed()
        ->expectsOutputToContain("This host has no plan with the slug 'starter' (named by demo-tessaline-works)");

    expect(Tenant::count())->toBe(0);
});

it('needs a blueprint or a portfolio, and not both', function () {
    $this->artisan('synthetic:materialize')->assertFailed()->expectsOutputToContain('Name a blueprint, or a --portfolio');
    $this->artisan('synthetic:materialize', ['blueprint' => 'demo-tessaline-works', '--portfolio' => 'demo-mixed-book'])
        ->assertFailed()
        ->expectsOutputToContain('not both');

    expect(Tenant::count())->toBe(0);
});
